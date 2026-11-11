<?php

declare(strict_types=1);

namespace OffsiteBackups\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OffsiteBackups\Http\Presenters\ClientCopyPresenter;
use OffsiteBackups\Models\Copy;
use OffsiteBackups\Services\Links;
use OffsiteBackups\Services\Restorer;
use OffsiteBackups\Services\Usage;
use Pterodactyl\Models\Server;

/** Off-site copies on a server's Backups page. Failed copies and database dumps stay hidden. */
final class ClientController
{
    public function __construct(private readonly Restorer $restorer, private readonly Links $links) {}

    public function index(Request $request, Server $server): JsonResponse
    {
        abort_unless($request->user()->can('backup.read', $server), 403);

        $usage = Usage::forServer($server);
        $copies = Copy::query()
            ->with('destination')
            ->where('server_id', $server->id)
            ->where('kind', 'backup')
            ->whereIn('status', ['queued', 'running', 'verified'])
            ->latest('id')
            ->limit(100)
            ->get();

        return new JsonResponse([
            'protected' => $usage['protected'],
            'destinations' => $usage['destinations'],
            'stored_bytes' => $usage['stored_bytes'],
            'count' => $usage['copies'],
            'copies' => ClientCopyPresenter::many($copies),
        ]);
    }

    public function restore(Request $request, Server $server, string $uuid): Response
    {
        abort_unless($request->user()->can('backup.restore', $server), 403);
        $data = $request->validate(['truncate' => ['sometimes', 'boolean']]);

        $this->restorer->restore($this->verified($server, $uuid), $request->user(), (bool) ($data['truncate'] ?? false));

        return response()->noContent();
    }

    public function download(Request $request, Server $server, string $uuid): JsonResponse
    {
        abort_unless($request->user()->can('backup.download', $server), 403);

        return new JsonResponse(['url' => $this->links->downloadUrl($this->verified($server, $uuid), $request->user())]);
    }

    private function verified(Server $server, string $uuid): Copy
    {
        return Copy::query()
            ->where('server_id', $server->id)
            ->where('uuid', $uuid)
            ->where('kind', 'backup')
            ->where('status', 'verified')
            ->firstOrFail();
    }
}
