<?php

declare(strict_types=1);

namespace OffsiteBackups\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OffsiteBackups\Http\Presenters\CopyPresenter;
use OffsiteBackups\Models\Copy;
use OffsiteBackups\Services\Usage;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;

final class OverviewController
{
    public function overview(): JsonResponse
    {
        return new JsonResponse([
            ...Usage::overview(),
            'recent' => CopyPresenter::many(Copy::query()->with(CopyPresenter::WITH)->latest('id')->limit(10)->get()),
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:nodes,eggs,servers'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = mb_trim((string) ($data['search'] ?? ''));
        $like = '%'.addcslashes($search, '%_\\').'%';
        $query = match ($data['type']) {
            'nodes' => Node::query()->toBase(),
            'eggs' => Egg::query()->toBase(),
            default => Server::query()->toBase(),
        };

        if ($search !== '') {
            $query->where(static function ($query) use ($data, $like, $search): void {
                $query->where('name', 'like', $like);

                if ($data['type'] === 'servers') {
                    $query->orWhere('uuid', 'like', $like)->orWhere('uuidShort', 'like', $like);
                }
                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }
            });
        }

        return new JsonResponse([
            'data' => $query->orderBy('name')->limit(25)->get(['id', 'name'])->map(static fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])->all(),
        ]);
    }
}
