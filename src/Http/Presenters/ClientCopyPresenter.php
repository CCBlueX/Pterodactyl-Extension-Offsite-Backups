<?php

declare(strict_types=1);

namespace OffsiteBackups\Http\Presenters;

use OffsiteBackups\Models\Copy;

/** What a server's users see of a copy: no failures, errors, paths or destination details. */
final readonly class ClientCopyPresenter
{
    /**
     * @param  iterable<Copy>  $copies
     * @return list<array<string, mixed>>
     */
    public static function many(iterable $copies): array
    {
        $copies = collect($copies);
        $local = CopyPresenter::localBackups($copies);

        return $copies->map(static fn (Copy $copy): array => [
            'uuid' => $copy->uuid,
            'backup_name' => $copy->backup_name,
            'backup_uuid' => $copy->backup_uuid,
            'kind' => $copy->kind,
            'local' => isset($local[$copy->backup_uuid ?? '']),
            'status' => $copy->status === 'verified' ? 'off-site' : 'copying',
            'progress' => ['done' => $copy->done_bytes, 'total' => $copy->source_bytes],
            'bytes' => $copy->source_bytes,
            'created_at' => CopyPresenter::date($copy->created_at),
            'destination' => $copy->destination->name,
        ])->values()->all();
    }
}
