<?php

declare(strict_types=1);

namespace OffsiteBackups\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OffsiteBackups\Formats\Archive;
use OffsiteBackups\Formats\Dedup;
use OffsiteBackups\Formats\EloquentBlobIndex;
use OffsiteBackups\Models\Copy;
use OffsiteBackups\Models\Destination;
use OffsiteBackups\Models\Rule;
use OffsiteBackups\Models\Run;
use OffsiteBackups\Models\Stored;
use OffsiteBackups\Panel\Panel;
use OffsiteBackups\Rules\Matcher;
use OffsiteBackups\Transfers\Connector;
use OffsiteBackups\Transfers\Repositories;
use OffsiteBackups\Transfers\Runner;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Creates, retries, checks and deletes off-site copies.
 */
final readonly class Copies
{
    public function __construct(
        private Panel $panel,
        private Connector $connector,
        private Archive $archive,
        private Dedup $dedup,
        private Repositories $repositories,
    ) {}

    /** Queues a copy of a finished backup for every destination of the rules that apply to its server. */
    public function queueForBackup(Backup $backup, ?Rule $only = null): int
    {
        $run = Run::query()->with('rule')->where('backup_uuid', $backup->uuid)->first();
        $run?->update(['status' => 'done']);

        $server = $this->panel->server((int) $backup->server_id);

        if ($server === null || ! $backup->is_successful || ! Allowance::for($server)['enabled']) {
            return 0;
        }

        $rules = $only !== null ? collect([$only]) : Matcher::rulesFor($server, 'backup');

        // The rule that asked for this backup comes first, so its retention applies to the destinations it names.
        if ($only === null && $run?->rule !== null && $run->rule->enabled) {
            $rules = $rules->reject(static fn (Rule $rule): bool => $rule->id === $run->rule->id)->prepend($run->rule)->values();
        }

        $targets = $this->targets($rules, fallback: false);
        $stored = Stored::query()->whereKey($backup->uuid)->value('destination_id');
        $existing = Copy::query()->where('backup_uuid', $backup->uuid)->pluck('destination_id')->map(intval(...))->all();
        $created = 0;

        foreach ($targets as $destinationId => [$destination, $rule]) {
            // Wings already put this backup into that bucket itself.
            if ($destinationId === $stored || in_array($destinationId, $existing, true)) {
                continue;
            }

            $this->make($server, $destination, $rule, $backup);
            $created++;
        }

        foreach ($targets as [$destination, $rule]) {
            if ($rule === null || ! $rule->include_databases) {
                continue;
            }

            foreach ($this->panel->databases($server) as $database) {
                $this->make($server, $destination, $rule, null, (string) $database->database);
                $created++;
            }
        }

        return $created;
    }

    /** "Copy latest backup now": the latest finished backup to the given destination, the server's rule destinations or the default. */
    public function queueLatest(Server $server, ?Destination $destination = null): ?Copy
    {
        $backup = $this->panel->latestBackup($server);

        if ($backup === null) {
            return null;
        }

        $targets = $destination !== null ? [$destination->id => [$destination, null]] : $this->targets(Matcher::rulesFor($server), fallback: true);
        $copies = [];

        foreach ($targets as $destinationId => [$target, $rule]) {
            $copy = Copy::query()->where('backup_uuid', $backup->uuid)->where('destination_id', $destinationId)->first();

            if ($copy === null) {
                $copy = $this->make($server, $target, $rule, $backup);
            } elseif ($copy->status === 'failed') {
                $this->retry($copy);
            }

            $copies[] = $copy;
        }

        return $copies[0] ?? null;
    }

    public function retry(Copy $copy): void
    {
        if ($copy->status === 'running') {
            throw new ConflictHttpException('This copy is running right now.');
        }

        if ($copy->status === 'verified') {
            throw new ConflictHttpException('This copy is already finished.');
        }

        $copy->forceFill(['status' => 'queued', 'attempts' => 0, 'next_attempt_at' => null, 'error' => null, 'finished_at' => null, 'done_bytes' => 0])->save();
    }

    public function delete(Copy $copy): void
    {
        if ($copy->status === 'running') {
            throw new ConflictHttpException('This copy is running right now. Wait for it to finish.');
        }

        $destination = $copy->destination;

        if ($copy->path !== null) {
            try {
                [$store] = $this->connector->open($destination);
                $store->delete($copy->path);
            } catch (Throwable $exception) {
                throw new HttpException(502, "Could not delete the copy from {$destination->name}: {$exception->getMessage()}", $exception);
            }
        }

        if ($destination->isDedup()) {
            $this->repositories->markDirty($destination->id, $copy->server_uuid);
        }

        $copy->delete();
    }

    /** The worker reads the copy back on its next round; a full read of a big copy outlasts any web request. */
    public function requestCheck(Copy $copy): void
    {
        if ($copy->status !== 'verified' || $copy->path === null) {
            throw new ConflictHttpException('Only finished copies can be checked.');
        }

        $copy->forceFill(['details' => ['check' => 'requested'] + ($copy->details ?? [])])->save();
    }

    /** Reads the whole copy back and compares it with its checksum. */
    public function check(Copy $copy): bool
    {
        if ($copy->status !== 'verified' || $copy->path === null) {
            throw new ConflictHttpException('Only finished copies can be checked.');
        }

        $destination = $copy->destination;

        try {
            [$store, $keys] = $this->connector->open($destination);

            if ($destination->isDedup()) {
                $repository = $this->repositories->find($destination->id, $copy->server_uuid) ?? throw new ConflictHttpException('The deduplicated storage of this copy is missing.');
                $this->repositories->reading($repository);
                $ok = $this->dedup->check($store, new EloquentBlobIndex($repository), $copy->path, $keys, (string) ($copy->details['tar_sha256'] ?? ''));
            } else {
                $ok = $this->archive->check($store, $copy->path, $keys, (string) (Runner::sha1Of($copy->checksum) ?? $copy->details['sha1'] ?? ''));
            }

            $error = $ok ? null : 'The copy does not match its checksum.';
        } catch (ConflictHttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $ok = false;
            $error = $exception->getMessage();
        }

        $details = $copy->details ?? [];
        unset($details['check']);

        $copy->forceFill(['checked_at' => CarbonImmutable::now(), 'error' => $error, 'details' => $details] + ($ok ? [] : ['status' => 'failed']))->save();

        return $ok;
    }

    /**
     * Destinations of the rules, each with the first rule that named it; with $fallback and no rule, the default destination.
     *
     * @param  Collection<int, Rule>  $rules
     * @return array<int, array{0: Destination, 1: Rule|null}>
     */
    private function targets(Collection $rules, bool $fallback): array
    {
        $order = [];

        foreach ($rules as $rule) {
            foreach (array_map(intval(...), $rule->destination_ids) as $id) {
                $order[$id] ??= $rule;
            }
        }

        if ($order === []) {
            $default = $fallback ? Destination::query()->where('is_default', true)->first() : null;

            return $default === null ? [] : [$default->id => [$default, null]];
        }

        $destinations = Destination::query()->whereIn('id', array_keys($order))->get()->keyBy('id');
        $targets = [];

        foreach ($order as $id => $rule) {
            if ($destinations->has($id)) {
                $targets[$id] = [$destinations[$id], $rule];
            }
        }

        return $targets;
    }

    private function make(Server $server, Destination $destination, ?Rule $rule, ?Backup $backup, ?string $database = null): Copy
    {
        return Copy::query()->create([
            'uuid' => (string) Str::uuid(),
            'server_id' => $server->id,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'node_id' => $server->node_id,
            'backup_uuid' => $database === null ? $backup?->uuid : null,
            'backup_name' => $database === null ? (string) $backup?->name : "Database {$database}",
            'kind' => $database === null ? 'backup' : 'database',
            'database_name' => $database,
            'destination_id' => $destination->id,
            'rule_id' => $rule?->id,
            'status' => 'queued',
            'source_bytes' => $database === null ? $backup?->bytes : null,
            'checksum' => $database === null ? $backup?->checksum : null,
        ]);
    }
}
