<?php

declare(strict_types=1);

namespace OffsiteBackups\Listeners;

use OffsiteBackups\Models\Run;
use OffsiteBackups\Panel\Panel;
use OffsiteBackups\Services\Copies;
use Pterodactyl\Events\Server\OperationCompleted;

/**
 * Queues off-site copies when a backup finishes. Only rows are written here; the worker does the transfer.
 */
final readonly class BackupListener
{
    public function __construct(private Panel $panel, private Copies $copies) {}

    public function handle(OperationCompleted $event): void
    {
        if ($event->operation !== 'backup') {
            return;
        }

        if (! $event->successful) {
            Run::query()->where('backup_uuid', $event->resourceUuid)->where('status', 'started')->update(['status' => 'failed', 'message' => 'Wings reported the backup as failed.']);

            return;
        }

        $backup = $this->panel->backup($event->resourceUuid);

        if ($backup !== null) {
            $this->copies->queueForBackup($backup);
        }
    }
}
