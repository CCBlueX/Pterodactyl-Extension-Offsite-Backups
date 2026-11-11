<?php

declare(strict_types=1);

namespace OffsiteBackups\Panel\Storage;

use OffsiteBackups\Models\Stored;
use Pterodactyl\Contracts\Backups\DeletesBackups;
use Pterodactyl\Models\Backup;

/**
 * Deletes a backup Wings uploaded to one of our destinations from that bucket.
 */
final readonly class StoringDeleter implements DeletesBackups
{
    public function __construct(private DeletesBackups $inner) {}

    public function delete(Backup $backup): void
    {
        if ($backup->disk !== Backup::ADAPTER_AWS_S3) {
            $this->inner->delete($backup);

            return;
        }

        $disks = app(Disks::class);
        $destination = $disks->storedIn($backup);

        if ($destination === null) {
            $disks->withCore(fn () => $this->inner->delete($backup));

            return;
        }

        $disks->with(null, $destination, fn () => $this->inner->delete($backup));
        Stored::query()->whereKey($backup->uuid)->delete();
    }
}
