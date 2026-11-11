<?php

declare(strict_types=1);

namespace OffsiteBackups\Panel\Storage;

use Pterodactyl\Contracts\Backups\PresignsBackupUploads;
use Pterodactyl\Models\Backup;

/**
 * Hands Wings upload links for the bucket the backup goes to.
 */
final readonly class StoringPresigner implements PresignsBackupUploads
{
    public function __construct(private PresignsBackupUploads $inner) {}

    public function presign(Backup $backup, int $size): array
    {
        $disks = app(Disks::class);
        $plan = $disks->planFor($backup);

        if ($plan === null) {
            return $this->inner->presign($backup, $size);
        }

        return $disks->with($plan[0], $plan[1], fn (): array => $this->inner->presign($backup, $size));
    }
}
