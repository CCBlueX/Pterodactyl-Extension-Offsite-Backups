<?php

declare(strict_types=1);

namespace OffsiteBackups\Console;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Command;
use OffsiteBackups\Formats\Dedup;
use OffsiteBackups\Formats\EloquentBlobIndex;
use OffsiteBackups\Formats\Paths;
use OffsiteBackups\Models\Copy;
use OffsiteBackups\Models\Link;
use OffsiteBackups\Models\Repository;
use OffsiteBackups\Models\Restore;
use OffsiteBackups\Models\Run;
use OffsiteBackups\Notifications\Discord;
use OffsiteBackups\Panel\Panel;
use OffsiteBackups\Services\Copies;
use OffsiteBackups\Services\Retention;
use OffsiteBackups\Settings;
use OffsiteBackups\Transfers\Connector;
use OffsiteBackups\Transfers\Repositories;
use Throwable;

final class MaintainCommand extends Command
{
    private const int RUN_DAYS = 30;

    /** A restore Wings never reported is closed after this long. */
    private const int RESTORE_HOURS = 24;

    protected $signature = 'offsite-backups:maintain';

    protected $description = 'Remove copies of deleted servers, apply retention, prune deduplicated storage and tidy up.';

    public function handle(Settings $settings, Copies $copies, Retention $retention, Discord $discord, Panel $panel, Connector $connector, Dedup $dedup, Repositories $repositories): int
    {
        ini_set('memory_limit', '512M');
        set_time_limit(0);

        $now = CarbonImmutable::now();

        $this->step('orphans', fn () => $this->orphans($settings, $copies, $discord, $panel, $now));
        $this->step('retention', fn () => $this->info("Retention removed {$retention->sweep()} copies."));
        $this->step('prune', fn () => $this->prune($connector, $dedup, $repositories, $discord));
        $this->step('tidy', fn () => $this->tidy($panel, $now));

        return self::SUCCESS;
    }

    private function step(string $name, Closure $step): void
    {
        try {
            $step();
        } catch (Throwable $exception) {
            report($exception);
            $this->error("{$name}: {$exception->getMessage()}");
        }
    }

    /** Copies of deleted servers are kept for the grace period, counted from when this noticed the server was gone. */
    private function orphans(Settings $settings, Copies $copies, Discord $discord, Panel $panel, CarbonImmutable $now): void
    {
        $known = Copy::query()->whereNotNull('server_id')->distinct()->pluck('server_id')->map(intval(...))->all();
        $gone = array_diff($known, $panel->existingServerIds($known));

        if ($gone !== []) {
            Copy::query()->whereIn('server_id', $gone)->update(['server_id' => null]);
        }

        $cutoff = $now->subDays($settings->orphanGraceDays());
        $deleted = 0;
        $freed = 0;
        $servers = [];

        Copy::query()->with('destination')->whereNull('server_id')->where('status', '!=', 'running')->each(function (Copy $copy) use ($copies, $now, $cutoff, &$deleted, &$freed, &$servers): void {
            $since = $copy->details['orphaned_at'] ?? null;

            if (! is_string($since)) {
                $copy->forceFill(['details' => ($copy->details ?? []) + ['orphaned_at' => $now->toIso8601String()]])->save();
                $since = $now->toIso8601String();
            }

            if (CarbonImmutable::parse($since)->gt($cutoff)) {
                return;
            }

            try {
                $copies->delete($copy);
                $deleted++;
                $freed += $copy->stored_bytes;
                $servers[$copy->server_name] = true;
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        $this->info("Removed {$deleted} copies of deleted servers.");

        if ($deleted > 0) {
            $discord->send('cleanup', 'Copies of deleted servers removed', [
                'Copies' => (string) $deleted,
                'Servers' => mb_substr(implode(', ', array_keys($servers)), 0, 1000),
                'Freed' => Discord::bytes($freed),
            ]);
        }
    }

    private function prune(Connector $connector, Dedup $dedup, Repositories $repositories, Discord $discord): void
    {
        foreach (Repository::query()->with('destination')->where('dirty', true)->get() as $repository) {
            $lock = $repositories->lock($repository);

            if (! $lock->get()) {
                continue;
            }

            $repositories->startPruning($repository);

            try {
                if ($repositories->isRead($repository)) {
                    $this->info("Skipped {$repository->server_uuid} on {$repository->destination->name}: a restore or download is reading it.");

                    continue;
                }

                [$store, $keys] = $connector->open($repository->destination);
                $live = Copy::query()->where('destination_id', $repository->destination_id)->where('server_uuid', $repository->server_uuid)
                    ->whereNotNull('path')->pluck('path')->all();
                $result = $dedup->prune($store, new EloquentBlobIndex($repository), $live, $keys, Paths::repository($repository->server_uuid));

                $repository->forceFill(['dirty' => false, 'pruned_at' => CarbonImmutable::now()])->save();
                $this->info("Pruned {$repository->server_uuid} on {$repository->destination->name}: {$result['deleted_packs']} packs deleted, {$result['repacked']} repacked.");

                if ($result['deleted_packs'] > 0 || $result['repacked'] > 0) {
                    $server = Copy::query()->where('server_uuid', $repository->server_uuid)->latest('id')->value('server_name');

                    $discord->send('pruned', 'Deduplicated storage pruned', [
                        'Server' => $server ?? $repository->server_uuid,
                        'Destination' => $repository->destination->name,
                        'Packs deleted' => (string) $result['deleted_packs'],
                        'Repacked' => (string) $result['repacked'],
                        'Freed' => Discord::bytes((int) $result['freed']),
                    ]);
                }
            } catch (Throwable $exception) {
                report($exception);
                $this->error("Pruning {$repository->server_uuid} failed: {$exception->getMessage()}");
            } finally {
                $repositories->stopPruning($repository);
                $lock->release();
            }
        }
    }

    private function tidy(Panel $panel, CarbonImmutable $now): void
    {
        Link::query()->where('expires_at', '<', $now)->delete();

        Run::query()->whereIn('status', ['done', 'skipped', 'failed'])->where('updated_at', '<', $now->subDays(self::RUN_DAYS))->delete();

        Restore::query()->where('status', 'running')->where('created_at', '<', $now->subHours(self::RESTORE_HOURS))->each(static function (Restore $restore) use ($panel): void {
            $restore->update(['status' => 'failed']);

            if ($restore->revived !== null) {
                $panel->trashBackup($restore->backup_uuid);
            }
        });
    }
}
