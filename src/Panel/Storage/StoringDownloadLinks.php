<?php

declare(strict_types=1);

namespace OffsiteBackups\Panel\Storage;

use Pterodactyl\Contracts\Backups\GeneratesBackupDownloadLinks;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\User;

/**
 * Signs download links (and core restores) against the bucket the backup was uploaded to.
 */
final readonly class StoringDownloadLinks implements GeneratesBackupDownloadLinks
{
    public function __construct(private GeneratesBackupDownloadLinks $inner) {}

    public function generate(Backup $backup, User $user): string
    {
        if ($backup->disk !== Backup::ADAPTER_AWS_S3) {
            return $this->inner->generate($backup, $user);
        }

        $disks = app(Disks::class);
        $destination = $disks->storedIn($backup);

        return $destination === null
            ? $disks->withCore(fn (): string => $this->inner->generate($backup, $user))
            : $disks->with(null, $destination, fn (): string => $this->inner->generate($backup, $user));
    }
}
