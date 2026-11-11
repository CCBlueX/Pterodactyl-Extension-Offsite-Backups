<?php

declare(strict_types=1);

namespace OffsiteBackups\Formats;

use DateTimeInterface;

/** Object paths relative to the destination root. */
final readonly class Paths
{
    public const string VAULT = 'offsite-backups.json';

    public static function archive(string $serverUuid, string $backupUuid, bool $encrypted): string
    {
        return "servers/{$serverUuid}/backups/{$backupUuid}.tar.gz".($encrypted ? '.enc' : '');
    }

    public static function database(string $serverUuid, string $database, DateTimeInterface $at, bool $encrypted): string
    {
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $database);

        return "servers/{$serverUuid}/databases/{$name}-{$at->format('Ymd-His')}.sql.gz".($encrypted ? '.enc' : '');
    }

    public static function repository(string $serverUuid): string
    {
        return "servers/{$serverUuid}/repo";
    }

    public static function manifest(string $serverUuid, string $copyUuid): string
    {
        return self::repository($serverUuid)."/manifests/{$copyUuid}";
    }

    /** The repository a manifest belongs to. */
    public static function repositoryOf(string $manifestPath): string
    {
        return dirname($manifestPath, 2);
    }

    public static function packs(string $repository): string
    {
        return "{$repository}/packs";
    }

    public static function pack(string $repository, string $name): string
    {
        return "{$repository}/packs/".mb_substr($name, 0, 2, '8bit')."/{$name}";
    }

    /** Where Wings stores a backup itself, at the bucket root. */
    public static function wingsObject(string $serverUuid, string $backupUuid): string
    {
        return "{$serverUuid}/{$backupUuid}.tar.gz";
    }
}
