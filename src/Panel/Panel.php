<?php

declare(strict_types=1);

namespace OffsiteBackups\Panel;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use OffsiteBackups\Models\Copy;
use Psr\Http\Message\StreamInterface;
use Pterodactyl\Contracts\Backups\DeletesBackups;
use Pterodactyl\Contracts\Backups\InitiatesBackups;
use Pterodactyl\Enum\JwtScope;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Facades\Daemon;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Database;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Nodes\NodeJwt;
use RuntimeException;

/**
 * The only place this extension touches Panel internals.
 */
final readonly class Panel
{
    public function __construct(private NodeJwt $jwt) {}

    public function server(int $id): ?Server
    {
        return Server::query()->find($id);
    }

    public function serverByUuid(string $uuid): ?Server
    {
        return Server::query()->where('uuid', $uuid)->first();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int> The ones that still exist.
     */
    public function existingServerIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Server::query()->whereIn('id', $ids)->toBase()->pluck('id')->map(intval(...))->values()->all();
    }

    public function user(int $id): ?User
    {
        return User::query()->find($id);
    }

    public function backup(string $uuid, bool $withTrashed = false): ?Backup
    {
        return Backup::query()->when($withTrashed, static fn ($query) => $query->withTrashed())->where('uuid', $uuid)->first();
    }

    public function latestBackup(Server $server): ?Backup
    {
        return Backup::query()->where('server_id', $server->id)->where('is_successful', true)->whereNotNull('completed_at')->latest('completed_at')->first();
    }

    /** @return Collection<int, Backup> Finished local backups, newest first. */
    public function localBackups(Server $server): Collection
    {
        return Backup::query()->with('server')->where('server_id', $server->id)->where('is_successful', true)->whereNotNull('completed_at')->orderByDesc('completed_at')->get();
    }

    /**
     * The archive of a node backup, streamed straight from Wings with a single-use token.
     *
     * @return array{0: StreamInterface, 1: int|null} The body and its Content-Length.
     *
     * @throws RuntimeException With the HTTP status as code when Wings refuses.
     */
    public function backupStream(Server $server, string $backupUuid): array
    {
        $node = $server->node;
        // Wings refuses backup tokens without a user (its revocation check needs one); the owner may always download.
        $token = $this->jwt->issue(
            $node,
            JwtScope::BackupDownload,
            identifiedBy: 'offsite'.$backupUuid,
            expiresAt: CarbonImmutable::now()->addMinutes(15),
            user: User::query()->findOrFail($server->owner_id),
            claims: ['backup_uuid' => $backupUuid, 'server_uuid' => $server->uuid],
        );

        $response = Http::withOptions(['stream' => true, 'verify' => App::isProduction(), 'read_timeout' => 300])
            ->connectTimeout(15)
            ->timeout(0)
            ->get($node->getConnectionAddress().'/download/backup', ['token' => $token->toString()]);
        $body = $response->toPsrResponse()->getBody();

        if (! $response->successful()) {
            $decoded = json_decode($body->read(500), true);
            $message = is_array($decoded) ? ($decoded['error'] ?? null) : null;
            $body->close();

            throw new RuntimeException(sprintf('Wings answered HTTP %d for the backup download%s', $response->status(), is_string($message) ? ": {$message}" : '.'), $response->status());
        }

        $length = $response->header('Content-Length');

        return [$body, is_numeric($length) ? (int) $length : $body->getSize()];
    }

    public function initiateBackup(Server $server, string $name, bool $override): Backup
    {
        return app(InitiatesBackups::class)->setIsLocked(false)->setIgnoredFiles([])->initiate($server, $name, $override);
    }

    public function deleteBackup(Backup $backup): void
    {
        app(DeletesBackups::class)->delete($backup);
    }

    /**
     * Makes sure a backup row with the copy's uuid exists for Wings' restore report.
     *
     * @return array{0: Backup, 1: 'trashed'|'created'|null} The row and how it came back.
     */
    public function reviveBackup(Server $server, Copy $copy): array
    {
        $backup = Backup::query()->withTrashed()->where('uuid', $copy->backup_uuid)->first();

        if ($backup !== null && (int) $backup->server_id !== $server->id) {
            throw new RuntimeException('The backup of this copy belongs to another server.');
        }

        if ($backup !== null && ! $backup->trashed()) {
            return [$backup, null];
        }

        if ($backup !== null) {
            $backup->restore();

            return [$backup, 'trashed'];
        }

        $backup = new Backup;
        $backup->forceFill([
            'server_id' => $server->id,
            'uuid' => $copy->backup_uuid,
            'name' => $copy->backup_name,
            'ignored_files' => [],
            'disk' => Backup::ADAPTER_WINGS,
            'checksum' => $copy->checksum,
            'bytes' => (int) ($copy->source_bytes ?? 0),
            'is_successful' => true,
            'is_locked' => false,
            'completed_at' => $copy->finished_at ?? $copy->created_at,
        ])->save();

        return [$backup, 'created'];
    }

    /** Undoes reviveBackup after a restore that never started. */
    public function unreviveBackup(Backup $backup, ?string $revived): void
    {
        match ($revived) {
            'trashed' => $backup->delete(),
            'created' => $backup->forceDelete(),
            default => null,
        };
    }

    /** Soft-deletes a backup row brought back for a restore, without touching its archive. */
    public function trashBackup(string $uuid): void
    {
        Backup::query()->where('uuid', $uuid)->first()?->delete();
    }

    /**
     * Asks Wings to restore the archive behind the URL. Wings fetches the URL before it answers.
     */
    public function restoreFromUrl(Server $server, Backup $backup, string $url, bool $truncate): void
    {
        $remote = clone $backup;
        $remote->disk = Backup::ADAPTER_AWS_S3;

        try {
            Daemon::server($server)->backups()->restore($remote, $url, $truncate);
        } catch (DaemonConnectionException $exception) {
            throw new RuntimeException($this->daemonError($exception), previous: $exception);
        }
    }

    public function setStatus(Server $server, ?string $status): void
    {
        $server->update(['status' => $status]);
    }

    public function isRestoring(Server $server): bool
    {
        return $server->status === Server::STATUS_RESTORING_BACKUP;
    }

    public function restoringStatus(): string
    {
        return Server::STATUS_RESTORING_BACKUP;
    }

    /** Logged the way core does, so ResetServerStates finds the backup of a restore that never reports back. */
    public function logRestore(Server $server, Backup $backup, ?User $user, bool $truncate): void
    {
        $activity = Activity::event('server:backup.restore')->subject($server, $backup)->property(['name' => $backup->name, 'truncate' => $truncate, 'via' => 'offsite-backups']);

        if ($user !== null) {
            $activity->actor($user);
        }

        $activity->log();
    }

    /** An unreachable node counts as running. */
    public function isOffline(Server $server): bool
    {
        try {
            return in_array(Daemon::server($server)->details()['state'] ?? 'offline', ['offline', 'stopped'], true);
        } catch (DaemonConnectionException) {
            return false;
        }
    }

    /** Offline and nothing happened on the server since then, apart from backups. */
    public function isIdle(Server $server, DateTimeInterface $since): bool
    {
        if (! $this->isOffline($server)) {
            return false;
        }

        $active = ActivityLog::query()->without('subjects')
            ->where('timestamp', '>', $since)
            ->where('event', 'not like', 'server:backup.%')
            ->whereExists(static fn (Builder $query) => $query->from('activity_log_subjects')
                ->whereColumn('activity_log_subjects.activity_log_id', 'activity_logs.id')
                ->where('activity_log_subjects.subject_type', $server->getMorphClass())
                ->where('activity_log_subjects.subject_id', $server->id))
            ->exists();

        return ! $active;
    }

    /**
     * The panel's own S3 backup bucket in destination config form, for backups Wings uploaded there.
     *
     * @return array<string, mixed>|null
     */
    public function coreS3Config(): ?array
    {
        $config = config('backups.disks.s3');

        if (! is_array($config) || empty($config['bucket'])) {
            return null;
        }

        return [
            'endpoint' => (string) ($config['endpoint'] ?? ''),
            'region' => (string) ($config['region'] ?? '') ?: 'us-east-1',
            'bucket' => (string) $config['bucket'],
            'prefix' => '',
            'key' => (string) ($config['key'] ?? ''),
            'secret' => (string) ($config['secret'] ?? ''),
            'path_style' => (bool) ($config['use_path_style_endpoint'] ?? false),
        ];
    }

    /** @return list<Database> */
    public function databases(Server $server): array
    {
        return Database::query()->with('host')->where('server_id', $server->id)->orderBy('id')->get()->all();
    }

    public function database(int $serverId, string $name): ?Database
    {
        return Database::query()->with('host')->where('server_id', $serverId)->where('database', $name)->first();
    }

    /**
     * The database host's own login, which reaches every database on it from the panel.
     *
     * @return array{host: string, port: int, username: string, password: string, database: string}
     */
    public function databaseLogin(Database $database): array
    {
        $host = $database->host;

        return [
            'host' => (string) $host->host,
            'port' => (int) $host->port,
            'username' => (string) $host->username,
            'password' => (string) Crypt::decrypt($host->password),
            'database' => (string) $database->database,
        ];
    }

    private function daemonError(DaemonConnectionException $exception): string
    {
        $previous = $exception->getPrevious();
        $error = $previous instanceof RequestException ? $previous->response->json('error') : null;

        return is_string($error) && $error !== '' ? $error : $exception->getMessage();
    }
}
