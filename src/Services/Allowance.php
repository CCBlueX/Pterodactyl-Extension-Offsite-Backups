<?php

declare(strict_types=1);

namespace OffsiteBackups\Services;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OffsiteBackups\Models\Destination;
use OffsiteBackups\Models\ServerSetting;
use OffsiteBackups\Settings;
use Pterodactyl\Models\Server;

/**
 * Per-server off-site settings: whether copies are made, quotas and where Wings stores new backups.
 */
final readonly class Allowance
{
    private const int GIB = 1024 * 1024 * 1024;

    /** @return array{enabled: bool, max_copies: ?int, max_storage_bytes: ?int, storage: string, storage_destination_id: ?int} */
    public static function for(Server|int $server): array
    {
        $setting = ServerSetting::query()->find($server instanceof Server ? $server->id : $server);

        return [
            'enabled' => $setting?->enabled ?? app(Settings::class)->defaultEnabled(),
            'max_copies' => $setting?->max_copies,
            'max_storage_bytes' => $setting?->max_storage_gb !== null ? $setting->max_storage_gb * self::GIB : null,
            'storage' => $setting->storage ?? 'default',
            'storage_destination_id' => $setting?->storage_destination_id,
        ];
    }

    /**
     * The stored values without defaults applied, as the admin forms edit them.
     *
     * @return array{enabled: ?bool, max_copies: ?int, max_storage_gb: ?int, storage: string, storage_destination_id: ?int}
     */
    public static function stored(int $serverId): array
    {
        $setting = ServerSetting::query()->find($serverId);

        return [
            'enabled' => $setting?->enabled,
            'max_copies' => $setting?->max_copies,
            'max_storage_gb' => $setting?->max_storage_gb,
            'storage' => $setting->storage ?? 'default',
            'storage_destination_id' => $setting?->storage_destination_id,
        ];
    }

    /**
     * Validates the request (every key optional when partial) and saves the merged values.
     *
     * @return array{enabled: ?bool, max_copies: ?int, max_storage_gb: ?int, storage: string, storage_destination_id: ?int}
     */
    public static function update(int $serverId, Request $request, bool $partial = false): array
    {
        $presence = $partial ? 'sometimes' : 'present';
        $data = $request->validate([
            'enabled' => [$presence, 'nullable', 'boolean'],
            'max_copies' => [$presence, 'nullable', 'integer', 'min:1', 'max:1000'],
            'max_storage_gb' => [$presence, 'nullable', 'integer', 'min:1', 'max:1000000'],
            'storage' => [$partial ? 'sometimes' : 'required', 'string', 'in:'.implode(',', ServerSetting::STORAGE)],
            'storage_destination_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $values = self::normalise(self::stored($serverId), $data);

        if ($values['storage'] === 's3') {
            $kind = $values['storage_destination_id'] !== null ? Destination::query()->whereKey($values['storage_destination_id'])->value('kind') : null;

            if ($kind !== 's3') {
                throw ValidationException::withMessages(['storage_destination_id' => 'Pick an S3 destination for this server\'s backups.']);
            }
        }

        if ($values === ['enabled' => null, 'max_copies' => null, 'max_storage_gb' => null, 'storage' => 'default', 'storage_destination_id' => null]) {
            ServerSetting::query()->whereKey($serverId)->delete();
        } else {
            ServerSetting::query()->updateOrCreate(['server_id' => $serverId], $values);
        }

        return $values;
    }

    /**
     * Merges validated input over the stored values; the destination only sticks with storage "s3".
     *
     * @param  array{enabled: ?bool, max_copies: ?int, max_storage_gb: ?int, storage: string, storage_destination_id: ?int}  $stored
     * @param  array<string, mixed>  $input
     * @return array{enabled: ?bool, max_copies: ?int, max_storage_gb: ?int, storage: string, storage_destination_id: ?int}
     */
    public static function normalise(array $stored, array $input): array
    {
        $merged = [...$stored, ...array_intersect_key($input, $stored)];
        $storage = in_array($merged['storage'], ServerSetting::STORAGE, true) ? $merged['storage'] : 'default';

        return [
            'enabled' => $merged['enabled'] === null ? null : (bool) $merged['enabled'],
            'max_copies' => $merged['max_copies'] === null ? null : (int) $merged['max_copies'],
            'max_storage_gb' => $merged['max_storage_gb'] === null ? null : (int) $merged['max_storage_gb'],
            'storage' => $storage,
            'storage_destination_id' => $storage === 's3' && $merged['storage_destination_id'] !== null ? (int) $merged['storage_destination_id'] : null,
        ];
    }
}
