<?php

declare(strict_types=1);

namespace OffsiteBackups\Panel\Storage;

use Closure;
use OffsiteBackups\Models\Destination;
use OffsiteBackups\Models\ServerSetting;
use OffsiteBackups\Models\Stored;
use OffsiteBackups\Settings;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;

/**
 * Points the panel's backup disks at a destination for one call. Core reads `backups.default` and
 * `backups.disks.s3` on every call, so a temporary config change is all it takes.
 */
final class Disks
{
    public const string NODE = 'wings';

    private static int $depth = 0;

    /** @var array{default: mixed, s3: mixed} The panel's own config, from before the outermost override. */
    private static array $core = ['default' => null, 's3' => null];

    public function __construct(private readonly Settings $settings) {}

    /** @return array<string, mixed> */
    public static function s3Config(Destination $destination): array
    {
        $config = $destination->config;

        return [
            'adapter' => Backup::ADAPTER_AWS_S3,
            'region' => ($config['region'] ?? '') ?: 'us-east-1',
            'key' => (string) ($config['key'] ?? ''),
            'secret' => (string) ($config['secret'] ?? ''),
            'bucket' => (string) ($config['bucket'] ?? ''),
            'endpoint' => ($config['endpoint'] ?? '') ?: null,
            'use_path_style_endpoint' => (bool) ($config['path_style'] ?? false),
            'prefix' => '',
            'storage_class' => ($config['storage_class'] ?? '') ?: null,
        ];
    }

    /** Where new backups of the server go: a destination, the node, or null for the panel's own config. */
    public function choiceFor(Server $server): Destination|string|null
    {
        $setting = ServerSetting::query()->find($server->id);

        if ($setting?->storage === 'node') {
            return self::NODE;
        }

        $id = $setting?->storage === 's3' && $setting->storage_destination_id !== null
            ? $setting->storage_destination_id
            : $this->settings->storageDestinationId();

        return $id === null ? null : $this->s3Destination($id);
    }

    /** The destination Wings uploaded this backup to, when it was one of ours. */
    public function storedIn(Backup $backup): ?Destination
    {
        $stored = Stored::query()->with('destination')->find($backup->uuid);

        return $stored?->destination?->kind === 's3' ? $stored->destination : null;
    }

    /**
     * The disks a later call about this backup needs (complete, presign); null leaves the panel's config alone.
     *
     * @return array{0: string, 1: Destination|null}|null
     */
    public function planFor(Backup $backup): ?array
    {
        $choice = null;

        if ($backup->disk === Backup::ADAPTER_AWS_S3) {
            $destination = $this->storedIn($backup);

            if ($destination === null && ($choice = $this->choiceFor($backup->server)) instanceof Destination) {
                $destination = $choice;
                $this->remember($backup, $destination);
            }

            return $destination === null ? null : [Backup::ADAPTER_AWS_S3, $destination];
        }

        // A node backup must not reach core's S3 code when the panel default is S3 but the server was moved off it.
        if (config('backups.default') !== self::NODE && $this->choiceFor($backup->server) !== null) {
            return [self::NODE, null];
        }

        return null;
    }

    public function remember(Backup $backup, Destination $destination): void
    {
        Stored::query()->updateOrCreate(['backup_uuid' => $backup->uuid], ['destination_id' => $destination->id]);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    public function with(?string $default, ?Destination $s3, Closure $call): mixed
    {
        return $this->apply($default, $s3 === null ? null : self::s3Config($s3), $call);
    }

    /**
     * Runs the call with the panel's own config, even inside one of our overrides.
     *
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    public function withCore(Closure $call): mixed
    {
        if (self::$depth === 0) {
            return $call();
        }

        return $this->apply(self::$core['default'], self::$core['s3'], $call);
    }

    /**
     * @template T
     *
     * @param  array<string, mixed>|null  $s3
     * @param  Closure(): T  $call
     * @return T
     */
    private function apply(mixed $default, ?array $s3, Closure $call): mixed
    {
        $previous = ['default' => config('backups.default'), 's3' => config('backups.disks.s3')];

        if (self::$depth === 0) {
            self::$core = $previous;
        }

        self::$depth++;

        try {
            config(array_filter(['backups.default' => $default, 'backups.disks.s3' => $s3], static fn (mixed $value): bool => $value !== null));

            return $call();
        } finally {
            config(['backups.default' => $previous['default'], 'backups.disks.s3' => $previous['s3']]);
            self::$depth--;
        }
    }

    private function s3Destination(int $id): ?Destination
    {
        $destination = Destination::query()->find($id);

        return $destination?->kind === 's3' ? $destination : null;
    }
}
