<?php

declare(strict_types=1);

namespace OffsiteBackups\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OffsiteBackups\Services\Allowance;
use OffsiteBackups\Services\Usage;
use Pterodactyl\Models\Server;

/** Per-server allowance for billing systems and other Application API clients. */
final class ApplicationController
{
    public function show(int $id): JsonResponse
    {
        $server = Server::query()->findOrFail($id);

        return new JsonResponse([
            'server' => ['id' => $server->id, 'uuid' => $server->uuid, 'name' => $server->name],
            'allowance' => Allowance::stored($server->id),
            'effective' => Allowance::for($server),
            'usage' => Usage::forServer($server),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        abort_unless(Server::query()->toBase()->where('id', $id)->exists(), 404);
        Allowance::update($id, $request, partial: true);

        return $this->show($id);
    }

    public function copies(Request $request, int $id): JsonResponse
    {
        abort_unless(Server::query()->toBase()->where('id', $id)->exists(), 404);
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:queued,running,verified,failed'],
            'kind' => ['nullable', 'string', 'in:backup,database'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return new JsonResponse(CopyController::page(CopyController::filtered([...$filters, 'server' => $id]), (int) ($filters['page'] ?? 1)));
    }
}
