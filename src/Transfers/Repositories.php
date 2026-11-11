<?php

declare(strict_types=1);

namespace OffsiteBackups\Transfers;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use OffsiteBackups\Models\Repository;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Coordinates work on a deduplicated repository: writes and prunes take its lock, readers mark it so a
 * prune waits, and a running prune refuses new readers.
 */
final readonly class Repositories
{
    /** Long enough for a large backup; a crashed worker frees the repository after this. */
    private const int LOCK_SECONDS = 3 * 3600;

    private const int READING_MINUTES = 15;

    public function for(int $destinationId, string $serverUuid): Repository
    {
        return Repository::query()->firstOrCreate(['destination_id' => $destinationId, 'server_uuid' => $serverUuid]);
    }

    public function find(int $destinationId, string $serverUuid): ?Repository
    {
        return Repository::query()->where('destination_id', $destinationId)->where('server_uuid', $serverUuid)->first();
    }

    public function lock(Repository $repository): Lock
    {
        return Cache::lock("offsite-backups:repository:{$repository->id}", self::LOCK_SECONDS);
    }

    public function markDirty(int $destinationId, string $serverUuid): void
    {
        Repository::query()->where('destination_id', $destinationId)->where('server_uuid', $serverUuid)->update(['dirty' => true]);
    }

    /** Call before reading (starting) and every few minutes while reading. */
    public function reading(Repository $repository, bool $starting = true): void
    {
        $repository->forceFill(['reading_until' => CarbonImmutable::now()->addMinutes(self::READING_MINUTES)])->save();

        if ($starting && Cache::has($this->pruningKey($repository))) {
            throw new ConflictHttpException('This storage is being cleaned up right now. Try again in a few minutes.');
        }
    }

    public function isRead(Repository $repository): bool
    {
        return $repository->fresh()?->reading_until?->isFuture() ?? false;
    }

    public function startPruning(Repository $repository): void
    {
        Cache::put($this->pruningKey($repository), true, self::LOCK_SECONDS);
    }

    public function stopPruning(Repository $repository): void
    {
        Cache::forget($this->pruningKey($repository));
    }

    private function pruningKey(Repository $repository): string
    {
        return "offsite-backups:pruning:{$repository->id}";
    }
}
