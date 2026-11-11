<?php

declare(strict_types=1);

namespace OffsiteBackups\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OffsiteBackups\Http\Presenters\CopyPresenter;
use OffsiteBackups\Models\Copy;
use OffsiteBackups\Services\Copies;
use OffsiteBackups\Services\Links;
use OffsiteBackups\Services\Restorer;
use Throwable;

final class CopyController
{
    public const int PER_PAGE = 25;

    public function __construct(private readonly Copies $copies, private readonly Restorer $restorer, private readonly Links $links) {}

    /**
     * @param  array<string, mixed>  $filters  search, status, destination, server, kind
     * @return Builder<Copy>
     */
    public static function filtered(array $filters): Builder
    {
        $search = mb_trim((string) ($filters['search'] ?? ''));

        return Copy::query()
            ->with(CopyPresenter::WITH)
            ->when($search !== '', static function (Builder $query) use ($search): void {
                $like = '%'.addcslashes($search, '%_\\').'%';
                $query->where(static fn (Builder $inner) => $inner
                    ->where('server_name', 'like', $like)
                    ->orWhere('backup_name', 'like', $like)
                    ->orWhere('database_name', 'like', $like)
                    ->orWhere('uuid', $search)
                    ->orWhere('server_uuid', 'like', $like)
                    ->orWhere('backup_uuid', $search));
            })
            ->when(($filters['status'] ?? null) !== null, static fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(($filters['destination'] ?? null) !== null, static fn (Builder $query) => $query->where('destination_id', (int) $filters['destination']))
            ->when(($filters['server'] ?? null) !== null, static fn (Builder $query) => $query->where('server_id', (int) $filters['server']))
            ->when(($filters['kind'] ?? null) !== null, static fn (Builder $query) => $query->where('kind', $filters['kind']))
            ->latest('id');
    }

    /**
     * @param  Builder<Copy>  $query
     * @return array{data: list<array<string, mixed>>, meta: array{page: int, per_page: int, total: int}}
     */
    public static function page(Builder $query, int $page): array
    {
        $page = max(1, $page);
        $total = (clone $query)->count();

        return [
            'data' => CopyPresenter::many($query->forPage($page, self::PER_PAGE)->get()),
            'meta' => ['page' => $page, 'per_page' => self::PER_PAGE, 'total' => $total],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:191'],
            'status' => ['nullable', 'string', 'in:'.implode(',', Copy::STATUSES)],
            'destination' => ['nullable', 'integer'],
            'server' => ['nullable', 'integer'],
            'kind' => ['nullable', 'string', 'in:backup,database'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return new JsonResponse(self::page(self::filtered($filters), (int) ($filters['page'] ?? 1)));
    }

    public function show(int $id): JsonResponse
    {
        return new JsonResponse(CopyPresenter::one(Copy::query()->findOrFail($id)));
    }

    public function retry(int $id): JsonResponse
    {
        $copy = Copy::query()->findOrFail($id);
        abort_unless($copy->status === 'failed', 409, 'Only failed copies can be retried.');

        $this->copies->retry($copy);

        return new JsonResponse(CopyPresenter::one($copy->refresh()));
    }

    public function check(int $id): JsonResponse
    {
        $copy = Copy::query()->findOrFail($id);
        abort_unless($copy->status === 'verified', 409, 'Only finished copies can be checked.');

        $this->copies->requestCheck($copy);

        return new JsonResponse(['ok' => true, 'message' => 'The worker reads the whole copy back within a few minutes and marks it checked or failed.']);
    }

    public function destroy(int $id): Response
    {
        $copy = Copy::query()->findOrFail($id);
        abort_if($copy->status === 'running', 409, 'This copy is being transferred right now.');

        $this->copies->delete($copy);

        return response()->noContent();
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', 'in:retry,delete'],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        set_time_limit(0);
        $ids = array_values(array_unique(array_map(intval(...), $data['ids'])));
        $done = 0;

        foreach (Copy::query()->whereKey($ids)->get() as $copy) {
            try {
                if ($data['action'] === 'retry' && $copy->status === 'failed') {
                    $this->copies->retry($copy);
                    $done++;
                } elseif ($data['action'] === 'delete' && $copy->status !== 'running') {
                    $this->copies->delete($copy);
                    $done++;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return new JsonResponse(['done' => $done, 'failed' => count($ids) - $done]);
    }

    public function download(Request $request, int $id): JsonResponse
    {
        $copy = Copy::query()->findOrFail($id);
        abort_unless($copy->status === 'verified', 409, 'Only finished copies can be downloaded.');

        return new JsonResponse(['url' => $this->links->downloadUrl($copy, $request->user())]);
    }

    public function restore(Request $request, int $id): Response
    {
        $data = $request->validate(['truncate' => ['sometimes', 'boolean']]);
        $copy = Copy::query()->findOrFail($id);
        abort_unless($copy->status === 'verified', 409, 'Only finished copies can be restored.');
        abort_unless($copy->kind === 'backup', 409, 'Database dumps cannot be restored from here. Download the dump and import it instead.');
        abort_if($copy->server_id === null, 409, 'The server of this copy does not exist anymore.');

        $this->restorer->restore($copy, $request->user(), (bool) ($data['truncate'] ?? false));

        return response()->noContent();
    }
}
