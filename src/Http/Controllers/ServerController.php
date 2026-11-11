<?php

declare(strict_types=1);

namespace OffsiteBackups\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OffsiteBackups\Http\Presenters\CopyPresenter;
use OffsiteBackups\Models\Copy;
use OffsiteBackups\Models\Destination;
use OffsiteBackups\Models\Rule;
use OffsiteBackups\Rules\Matcher;
use OffsiteBackups\Services\Allowance;
use OffsiteBackups\Services\Copies;
use OffsiteBackups\Services\Usage;
use OffsiteBackups\Settings;
use Pterodactyl\Models\Server;

/** The admin server tab: usage, allowance, storage choice and copies of one server. */
final class ServerController
{
    public function __construct(private readonly Copies $copies, private readonly Settings $settings) {}

    public function show(int $id): JsonResponse
    {
        $server = Server::query()->findOrFail($id);
        $storageId = $this->settings->storageDestinationId();

        return new JsonResponse([
            'server' => ['id' => $server->id, 'uuid' => $server->uuid, 'name' => $server->name],
            'usage' => Usage::forServer($server),
            'allowance' => Allowance::stored($server->id),
            'defaults' => [
                'enabled' => $this->settings->defaultEnabled(),
                'storage_destination' => $storageId !== null ? Destination::query()->whereKey($storageId)->value('name') : null,
            ],
            'rules' => Matcher::rulesFor($server)->map(static fn (Rule $rule): array => ['id' => $rule->id, 'name' => $rule->name])->all(),
            'destinations' => Destination::query()->orderBy('name')->get(['id', 'name', 'kind'])->map(static fn (Destination $destination): array => [
                'id' => $destination->id,
                'name' => $destination->name,
                'kind' => $destination->kind,
            ])->all(),
            'copies' => CopyPresenter::many(Copy::query()->with(CopyPresenter::WITH)->where('server_id', $server->id)->latest('id')->limit(100)->get()),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        abort_unless(Server::query()->toBase()->where('id', $id)->exists(), 404);
        Allowance::update($id, $request);

        return $this->show($id);
    }

    public function copy(Request $request, int $id): JsonResponse
    {
        $server = Server::query()->findOrFail($id);
        $data = $request->validate(['destination_id' => ['sometimes', 'nullable', 'integer']]);
        $destination = ($data['destination_id'] ?? null) !== null ? Destination::query()->findOrFail((int) $data['destination_id']) : null;

        $copy = $this->copies->queueLatest($server, $destination);

        return new JsonResponse(['copy' => $copy !== null ? CopyPresenter::one($copy) : null]);
    }
}
