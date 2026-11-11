<?php

declare(strict_types=1);

namespace OffsiteBackups\Panel\Storage;

use Pterodactyl\Contracts\Backups\CompletesBackups;
use Pterodactyl\Models\Backup;

/**
 * Finishes the multipart upload in the bucket the backup was uploaded to.
 */
final readonly class StoringCompleter implements CompletesBackups
{
    public function __construct(private CompletesBackups $inner) {}

    public function complete(Backup $backup, array $input): void
    {
        $disks = app(Disks::class);
        $plan = $disks->planFor($backup);

        if ($plan === null) {
            $this->inner->complete($backup, $input);

            return;
        }

        $disks->with($plan[0], $plan[1], fn () => $this->inner->complete($backup, $input));
    }
}
