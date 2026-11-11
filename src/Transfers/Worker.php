<?php

declare(strict_types=1);

namespace OffsiteBackups\Transfers;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use OffsiteBackups\Models\Copy;
use OffsiteBackups\Models\Destination;
use OffsiteBackups\Services\Copies;
use OffsiteBackups\Settings;
use OffsiteBackups\Support\Backoff;
use Throwable;

/**
 * Runs queued copies in one of `concurrency` slots. Each slot is a cache key a running worker keeps alive.
 */
final readonly class Worker
{
    public const string SEEN_KEY = 'offsite-backups:worker:seen';

    private const int SLOT_SECONDS = 90;

    private const int STALE_MINUTES = 10;

    private const int IDLE_SLEEP = 5;

    /** A check still marked running after this long belonged to a worker that died. */
    private const int STALE_CHECK_HOURS = 6;

    public function __construct(private Runner $runner, private Copies $copies, private Settings $settings) {}

    /**
     * @param  int  $maxSeconds  Stop starting new copies after this long; 0 runs until stopped.
     * @param  Closure(string): void|null  $log
     * @return int|null Copies run, or null when every slot was taken.
     */
    public function work(int $maxSeconds = 0, bool $once = false, ?Closure $log = null): ?int
    {
        [$slot, $owner] = $this->claimSlot() ?? [null, null];

        if ($slot === null) {
            return null;
        }

        $deadline = $maxSeconds > 0 ? time() + $maxSeconds : null;
        $heartbeat = fn () => $this->beat($slot, $owner);
        $done = 0;

        try {
            $this->requeueStale();

            while ($deadline === null || time() < $deadline) {
                if (! $heartbeat()) {
                    break;
                }

                $copy = $this->claim();

                if ($copy === null && ($check = $this->claimCheck()) !== null) {
                    $log?->__invoke("Checking {$check->backup_name} of {$check->server_name} on {$check->destination->name}");
                    $this->check($check);
                    $done++;

                    continue;
                }

                if ($copy === null) {
                    if ($once || $deadline !== null) {
                        break;
                    }

                    sleep(self::IDLE_SLEEP);
                    $this->requeueStale();

                    continue;
                }

                $log?->__invoke("Copying {$copy->backup_name} of {$copy->server_name} to {$copy->destination->name}");
                $this->runner->run($copy, $heartbeat);
                $done++;

                if ($once) {
                    break;
                }
            }
        } finally {
            if (Cache::get($this->slotKey($slot)) === $owner) {
                Cache::forget($this->slotKey($slot));
            }
        }

        return $done;
    }

    /** Copies left running by a worker that died get queued again; this counts as an attempt. */
    public function requeueStale(): int
    {
        $stale = CarbonImmutable::now()->subMinutes(self::STALE_MINUTES);
        $count = 0;

        Copy::query()->where('status', 'running')
            ->where(static fn (Builder $query) => $query->where('heartbeat_at', '<', $stale)->orWhere(static fn (Builder $query) => $query->whereNull('heartbeat_at')->where('updated_at', '<', $stale)))
            ->each(function (Copy $copy) use (&$count): void {
                $attempts = $copy->attempts + 1;
                $final = $attempts >= $this->settings->attempts();
                $count += Copy::query()->whereKey($copy->id)->where('status', 'running')->update([
                    'status' => $final ? 'failed' : 'queued',
                    'attempts' => $attempts,
                    'next_attempt_at' => $final ? null : Backoff::next($attempts, CarbonImmutable::now()),
                    'finished_at' => $final ? CarbonImmutable::now() : null,
                    'heartbeat_at' => null,
                    'error' => 'The worker stopped while copying.',
                ]);
            });

        Copy::query()->where('status', 'verified')->where('details->check', 'running')->where('updated_at', '<', CarbonImmutable::now()->subHours(self::STALE_CHECK_HOURS))
            ->update(['details->check' => 'requested']);

        return $count;
    }

    private function claimCheck(): ?Copy
    {
        $copy = Copy::query()->where('status', 'verified')->where('details->check', 'requested')->orderBy('id')->first();

        if ($copy === null || Copy::query()->whereKey($copy->id)->where('details->check', 'requested')->update(['details->check' => 'running']) !== 1) {
            return null;
        }

        return $copy->refresh()->load('destination');
    }

    private function check(Copy $copy): void
    {
        try {
            $this->copies->check($copy);
        } catch (Throwable $exception) {
            report($exception);
            $details = $copy->details ?? [];
            unset($details['check']);
            $copy->forceFill(['details' => $details, 'error' => $exception->getMessage()])->save();
        }
    }

    /** @return array{0: int, 1: string}|null */
    private function claimSlot(): ?array
    {
        $owner = Str::random(32);

        for ($slot = 1; $slot <= $this->settings->concurrency(); $slot++) {
            if (Cache::add($this->slotKey($slot), $owner, self::SLOT_SECONDS)) {
                return [$slot, $owner];
            }
        }

        return null;
    }

    /** Keeps the slot; false when another worker took it over. */
    private function beat(int $slot, string $owner): bool
    {
        $key = $this->slotKey($slot);
        $current = Cache::get($key);

        if ($current !== null && $current !== $owner) {
            return false;
        }

        Cache::put($key, $owner, self::SLOT_SECONDS);
        Cache::put(self::SEEN_KEY, CarbonImmutable::now()->toIso8601String(), 86400);

        return true;
    }

    private function claim(): ?Copy
    {
        $now = CarbonImmutable::now();

        for ($try = 0; $try < 5; $try++) {
            $copy = Copy::query()
                ->where('status', 'queued')
                ->where(static fn (Builder $query) => $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', $now))
                ->whereIn('destination_id', Destination::query()->where('status', '!=', 'paused')->select('id'))
                ->orderBy('id')
                ->first();

            if ($copy === null) {
                return null;
            }

            $claimed = Copy::query()->whereKey($copy->id)->where('status', 'queued')->update(['status' => 'running', 'heartbeat_at' => $now, 'updated_at' => $now]);

            if ($claimed === 1) {
                return $copy->refresh()->load('destination');
            }
        }

        return null;
    }

    private function slotKey(int $slot): string
    {
        return "offsite-backups:worker:{$slot}";
    }
}
