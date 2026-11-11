<?php

declare(strict_types=1);

namespace OffsiteBackups\Panel;

use OffsiteBackups\Models\Restore;
use OffsiteBackups\Notifications\Discord;
use Pterodactyl\Contracts\Backups\RestoresBackupStatuses;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Node;
use Throwable;

/**
 * Wings reports a finished restore. For a restore from an off-site copy this also ends our record of it
 * and puts a backup row we brought back for the report away again.
 */
final readonly class RestoreListener implements RestoresBackupStatuses
{
    public function __construct(private RestoresBackupStatuses $inner) {}

    public function restoreStatus(string $backupUuid, Node $node, bool $successful): Backup
    {
        $backup = $this->inner->restoreStatus($backupUuid, $node, $successful);
        $restore = Restore::query()->with('copy')->where('backup_uuid', $backupUuid)->where('status', 'running')->latest('id')->first();

        if ($restore === null) {
            return $backup;
        }

        try {
            $restore->update(['status' => $successful ? 'done' : 'failed']);

            if ($restore->revived !== null) {
                app(Panel::class)->trashBackup($backupUuid);
            }

            app(Discord::class)->send('restored', $successful ? 'Restored from off-site' : 'Restore from off-site failed', array_filter([
                'Server' => $restore->copy?->server_name,
                'Backup' => $restore->copy?->backup_name ?? $backup->name,
                'Destination' => $restore->copy?->destination?->name,
                'Error' => $successful ? null : 'Wings could not restore the archive.',
            ]), $successful ? null : Discord::RED);
        } catch (Throwable $exception) {
            report($exception);
        }

        return $backup;
    }
}
