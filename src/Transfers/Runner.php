<?php

declare(strict_types=1);

namespace OffsiteBackups\Transfers;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;
use OffsiteBackups\Formats\Archive;
use OffsiteBackups\Formats\Dedup;
use OffsiteBackups\Formats\EloquentBlobIndex;
use OffsiteBackups\Formats\Paths;
use OffsiteBackups\Formats\Result;
use OffsiteBackups\Models\Copy;
use OffsiteBackups\Models\Stored;
use OffsiteBackups\Notifications\Discord;
use OffsiteBackups\Panel\Panel;
use OffsiteBackups\Services\Retention;
use OffsiteBackups\Settings;
use OffsiteBackups\Storage\Store;
use OffsiteBackups\Storage\Stores;
use OffsiteBackups\Support\Backoff;
use OffsiteBackups\Transfers\Sources\DumpSource;
use OffsiteBackups\Transfers\Sources\NodeSource;
use OffsiteBackups\Transfers\Sources\Source;
use OffsiteBackups\Transfers\Sources\StoredSource;
use RuntimeException;
use Throwable;

/**
 * Makes one copy: streams the source into the destination's format and checks it against Wings' checksum.
 */
final readonly class Runner
{
    private const int PROGRESS_SECONDS = 2;

    /** A copy that waits for its repository is tried again after this, without counting as an attempt. */
    private const int BUSY_MINUTES = 2;

    public function __construct(
        private Panel $panel,
        private Settings $settings,
        private Connector $connector,
        private Stores $stores,
        private Archive $archive,
        private Dedup $dedup,
        private DatabaseDumper $dumper,
        private Repositories $repositories,
        private Retention $retention,
        private Discord $discord,
    ) {}

    /** The hex sha1 from Wings' "sha1:<hex>" checksum. */
    public static function sha1Of(?string $checksum): ?string
    {
        if ($checksum === null || ! str_starts_with($checksum, 'sha1:')) {
            return null;
        }

        return mb_strtolower(mb_substr($checksum, 5));
    }

    /** @param Closure(): void|null $heartbeat Called with every progress update, to keep the worker's slot. */
    public function run(Copy $copy, ?Closure $heartbeat = null): void
    {
        $now = CarbonImmutable::now();
        $copy->forceFill(['status' => 'running', 'started_at' => $now, 'heartbeat_at' => $now, 'done_bytes' => 0, 'error' => null, 'next_attempt_at' => null])->save();

        try {
            $result = $this->transfer($copy, $heartbeat);
        } catch (RepositoryBusy) {
            $copy->forceFill(['status' => 'queued', 'next_attempt_at' => CarbonImmutable::now()->addMinutes(self::BUSY_MINUTES), 'heartbeat_at' => null])->save();

            return;
        } catch (Throwable $exception) {
            $this->failed($copy, $exception);

            return;
        }

        if ($result === null) {
            return;
        }

        try {
            $this->retention->afterCopy($copy);
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->notify($copy, $result);
    }

    private function transfer(Copy $copy, ?Closure $heartbeat): ?Result
    {
        $destination = $copy->destination;
        [$store, $keys] = $this->connector->open($destination);
        $source = $this->source($copy);
        $progress = $this->progress($copy, $heartbeat);
        $database = $copy->kind === 'database';

        if ($destination->isDedup()) {
            $repository = $this->repositories->for($destination->id, $copy->server_uuid);
            $lock = $this->repositories->lock($repository);

            if (! $lock->get()) {
                throw new RepositoryBusy;
            }

            $path = Paths::manifest($copy->server_uuid, $copy->uuid);

            try {
                $this->open($copy, $source);
                $result = $this->dedup->write($store, new EloquentBlobIndex($repository), $path, $source->chunks(), $keys, ! $database, $progress);
            } catch (Throwable $exception) {
                $repository->forceFill(['dirty' => true])->save();

                throw $exception;
            } finally {
                $lock->release();
            }
        } else {
            $path = $database
                ? Paths::database($copy->server_uuid, (string) $copy->database_name, CarbonImmutable::now(), $keys !== null)
                : Paths::archive($copy->server_uuid, (string) $copy->backup_uuid, $keys !== null);
            $this->open($copy, $source);
            $result = $this->archive->write($store, $path, $source->chunks(), $keys, $progress);
        }

        $expected = self::sha1Of($copy->checksum);

        if (! $database && $expected !== null && ! hash_equals($expected, mb_strtolower($result->sha1))) {
            $this->discard($copy, $store, $path);

            throw new RuntimeException('The copy does not match the backup\'s checksum. The download from the node was probably cut short.');
        }

        $now = CarbonImmutable::now();
        $updated = Copy::query()->whereKey($copy->id)->where('status', 'running')->update([
            'status' => 'verified',
            'path' => $path,
            'source_bytes' => $result->sourceBytes,
            'done_bytes' => $result->sourceBytes,
            'stored_bytes' => $result->storedBytes,
            'checksum' => $database || $copy->checksum === null ? 'sha1:'.$result->sha1 : $copy->checksum,
            'details' => json_encode($result->details + ['sha1' => $result->sha1]),
            'verified_at' => $now,
            'heartbeat_at' => $now,
            'finished_at' => $now,
            'next_attempt_at' => null,
            'error' => null,
            'updated_at' => $now,
        ]);

        if ($updated === 0) {
            // Deleted or reset while it ran: the data written belongs to nobody.
            $this->discard($copy, $store, $path);

            return null;
        }

        $copy->refresh();

        return $result;
    }

    private function source(Copy $copy): Source
    {
        $server = $copy->server_id !== null ? $this->panel->server($copy->server_id) : null;

        if ($copy->kind === 'database') {
            $database = $server !== null ? $this->panel->database($server->id, (string) $copy->database_name) : null;

            return new DumpSource($this->dumper, $database ?? throw new Permanent('The database no longer exists.'));
        }

        $backup = $this->panel->backup((string) $copy->backup_uuid);

        if ($backup === null || ! $backup->is_successful) {
            throw new Permanent('The backup no longer exists on the panel.');
        }

        if ($backup->disk === 's3') {
            $stored = Stored::query()->with('destination')->find($backup->uuid);
            $store = $stored?->destination !== null ? $this->stores->for($stored->destination, true) : $this->coreStore();

            return new StoredSource($store, Paths::wingsObject($copy->server_uuid, $backup->uuid));
        }

        return new NodeSource($this->panel, $server ?? throw new Permanent('The server no longer exists.'), $backup->uuid);
    }

    private function open(Copy $copy, Source $source): void
    {
        $size = $source->open();

        if ($size !== null) {
            $copy->forceFill(['source_bytes' => $size])->save();
        }
    }

    /** The bucket the panel's own S3 backup config points at. */
    private function coreStore(): Store
    {
        $config = $this->panel->coreS3Config() ?? throw new Permanent('The panel has no S3 backup storage configured.');

        return $this->stores->fromConfig('s3', $config, true);
    }

    /** @return Closure(int): void */
    private function progress(Copy $copy, ?Closure $heartbeat): Closure
    {
        $last = 0.0;

        return function (int $bytes) use ($copy, $heartbeat, &$last): void {
            $now = microtime(true);

            if ($now - $last < self::PROGRESS_SECONDS) {
                return;
            }

            $last = $now;
            $at = CarbonImmutable::now();
            $updated = Copy::query()->whereKey($copy->id)->where('status', 'running')->update(['done_bytes' => $bytes, 'heartbeat_at' => $at, 'updated_at' => $at]);

            if ($updated === 0) {
                throw new Permanent('The copy was removed while it ran.');
            }

            if ($heartbeat !== null) {
                $heartbeat();
            }
        };
    }

    private function failed(Copy $copy, Throwable $exception): void
    {
        if (! $exception instanceof Permanent) {
            report($exception);
        }

        $fresh = Copy::query()->with('destination')->find($copy->id);

        if ($fresh === null || $fresh->status !== 'running') {
            return;
        }

        $copy = $fresh;

        $attempts = $copy->attempts + 1;
        $final = $exception instanceof Permanent || $attempts >= $this->settings->attempts();
        $now = CarbonImmutable::now();
        $message = Str::limit(mb_trim($exception->getMessage()) ?: $exception::class, 1000);

        $copy->forceFill([
            'status' => $final ? 'failed' : 'queued',
            'attempts' => $attempts,
            'next_attempt_at' => $final ? null : Backoff::next($attempts, $now),
            'heartbeat_at' => null,
            'finished_at' => $final ? $now : null,
            'error' => $message,
        ])->save();

        if ($final) {
            $this->discord->send('failed', 'Off-site copy failed', [
                'Server' => $copy->server_name,
                'Backup' => $copy->kind === 'database' ? "Database {$copy->database_name}" : $copy->backup_name,
                'Destination' => $copy->destination->name,
                'Attempts' => (string) $attempts,
                'Error' => $message,
            ]);
        }
    }

    private function notify(Copy $copy, Result $result): void
    {
        $duration = $copy->started_at !== null ? (int) $copy->started_at->diffInSeconds($copy->finished_at ?? CarbonImmutable::now(), true) : null;
        $database = $copy->kind === 'database';

        $this->discord->send($database ? 'database' : 'copied', $database ? 'Database dump stored off-site' : 'Backup copied off-site', [
            'Server' => $copy->server_name,
            $database ? 'Database' : 'Backup' => $database ? $copy->database_name : $copy->backup_name,
            'Size' => Discord::bytes($result->sourceBytes),
            'New data' => $copy->destination->isDedup() ? Discord::bytes($result->storedBytes) : null,
            'Duration' => $duration !== null ? Discord::duration($duration) : null,
            'Destination' => $copy->destination->name,
        ]);
    }

    private function discard(Copy $copy, Store $store, string $path): void
    {
        try {
            $store->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }

        if ($copy->destination->isDedup()) {
            $this->repositories->markDirty($copy->destination_id, $copy->server_uuid);
        }
    }
}
