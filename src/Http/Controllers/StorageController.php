<?php

declare(strict_types=1);

namespace OffsiteBackups\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OffsiteBackups\Models\Destination;
use OffsiteBackups\Models\ServerSetting;
use OffsiteBackups\Settings;

/** Where Wings writes new backups for servers that follow the panel default. */
final class StorageController
{
    public function __construct(private readonly Settings $settings) {}

    public function show(): JsonResponse
    {
        $settings = (new ServerSetting())->getTable();
        $destinations = (new Destination())->getTable();

        return new JsonResponse([
            'panel_driver' => config('backups.default'),
            'destination_id' => $this->settings->storageDestinationId(),
            'overrides' => ServerSetting::query()
                ->toBase()
                ->join('servers', 'servers.id', '=', "{$settings}.server_id")
                ->leftJoin($destinations, "{$destinations}.id", '=', "{$settings}.storage_destination_id")
                ->where("{$settings}.storage", '!=', 'default')
                ->orderBy('servers.name')
                ->get(["{$settings}.server_id", 'servers.name as server_name', "{$settings}.storage", "{$destinations}.name as destination"])
                ->map(static fn (object $row): array => [
                    'server_id' => (int) $row->server_id,
                    'server_name' => (string) $row->server_name,
                    'storage' => (string) $row->storage,
                    'destination' => $row->destination,
                ])
                ->all(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['destination_id' => ['present', 'nullable', 'integer']]);
        $id = $data['destination_id'] === null ? null : (int) $data['destination_id'];

        if ($id !== null && Destination::query()->whereKey($id)->value('kind') !== 's3') {
            throw ValidationException::withMessages(['destination_id' => 'Only S3 destinations can hold the backups Wings uploads.']);
        }

        $this->settings->setStorageDestinationId($id);

        return $this->show();
    }
}
