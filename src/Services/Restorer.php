<?php

declare(strict_types=1);

namespace OffsiteBackups\Services;

use OffsiteBackups\Models\Copy;
use OffsiteBackups\Models\Restore;
use OffsiteBackups\Panel\Panel;
use Pterodactyl\Models\User;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/**
 * Restores an off-site copy into its server: Wings downloads the archive from a single-use link.
 * Wings reports back by backup uuid, so the backup row is brought back for the report when needed.
 */
final readonly class Restorer
{
    public function __construct(private Panel $panel, private Links $links) {}

    public function restore(Copy $copy, User $user, bool $truncate): void
    {
        if ($copy->kind !== 'backup' || $copy->backup_uuid === null) {
            throw new BadRequestHttpException('Database dumps cannot be restored into a server. Download the dump instead.');
        }

        if ($copy->status !== 'verified') {
            throw new ConflictHttpException('Only finished copies can be restored.');
        }

        $server = ($copy->server_id !== null ? $this->panel->server($copy->server_id) : null) ?? throw new NotFoundHttpException('The server of this copy no longer exists.');

        if ($server->status !== null) {
            throw new ConflictHttpException('The server is busy (installing, restoring or suspended). Try again once it is ready.');
        }

        try {
            [$backup, $revived] = $this->panel->reviveBackup($server, $copy);
        } catch (Throwable $exception) {
            throw new ConflictHttpException($exception->getMessage(), $exception);
        }

        $restore = Restore::query()->create([
            'copy_id' => $copy->id,
            'server_id' => $server->id,
            'backup_uuid' => $copy->backup_uuid,
            'revived' => $revived,
            'user_id' => $user->id,
            'truncate' => $truncate,
            'status' => 'running',
        ]);

        try {
            $url = $this->links->restoreUrl($copy);
            $this->panel->setStatus($server, $this->panel->restoringStatus());
            // Wings fetches the link before it answers, so the link must already be committed here.
            $this->panel->restoreFromUrl($server, $backup, $url, $truncate);
        } catch (Throwable $exception) {
            $restore->update(['status' => 'failed']);

            if ($this->panel->isRestoring($server->refresh())) {
                $this->panel->setStatus($server, null);
            }

            $this->panel->unreviveBackup($backup, $revived);

            throw new ServiceUnavailableHttpException(null, 'Wings could not start the restore: '.$exception->getMessage(), $exception);
        }

        $this->panel->logRestore($server, $backup, $user, $truncate);
    }
}
