<?php

declare(strict_types=1);

namespace OffsiteBackups\Http\Presenters;

use DateTimeInterface;
use Illuminate\Support\Collection;
use OffsiteBackups\Models\Copy;
use Pterodactyl\Models\Backup;

final readonly class CopyPresenter
{
    /** Relations every presented copy needs. */
    public const array WITH = ['destination', 'rule:id,name'];

    /**
     * @param  iterable<Copy>  $copies
     * @return list<array<string, mixed>>
     */
    public static function many(iterable $copies): array
    {
        $copies = collect($copies);
        $local = self::localBackups($copies);

        return $copies->map(static fn (Copy $copy): array => self::present($copy, isset($local[$copy->backup_uuid ?? ''])))->values()->all();
    }

    /** @return array<string, mixed> */
    public static function one(Copy $copy): array
    {
        return self::many([$copy->loadMissing(self::WITH)])[0];
    }

    /**
     * Backup uuids whose Backup row still exists and is not soft-deleted.
     *
     * @param  Collection<int, Copy>  $copies
     * @return array<string, true>
     */
    public static function localBackups(Collection $copies): array
    {
        $uuids = $copies->pluck('backup_uuid')->filter()->unique()->values()->all();

        return $uuids === [] ? [] : array_fill_keys(Backup::query()->whereIn('uuid', $uuids)->pluck('uuid')->all(), true);
    }

    /** @return array<string, mixed> */
    public static function present(Copy $copy, bool $local): array
    {
        $destination = $copy->destination;

        return [
            'id' => $copy->id,
            'uuid' => $copy->uuid,
            'server' => ['id' => $copy->server_id, 'uuid' => $copy->server_uuid, 'name' => $copy->server_name],
            'backup' => ['uuid' => $copy->backup_uuid, 'name' => $copy->backup_name, 'local' => $local],
            'kind' => $copy->kind,
            'database' => $copy->database_name,
            'destination' => ['id' => $destination->id, 'name' => $destination->name, 'kind' => $destination->kind, 'format' => $destination->format],
            'rule' => $copy->rule !== null ? ['id' => $copy->rule->id, 'name' => $copy->rule->name] : null,
            'status' => $copy->status,
            'attempts' => $copy->attempts,
            'progress' => ['done' => $copy->done_bytes, 'total' => $copy->source_bytes],
            'source_bytes' => $copy->source_bytes,
            'stored_bytes' => $copy->stored_bytes,
            'checksum' => $copy->checksum,
            'verified_at' => self::date($copy->verified_at),
            'checked_at' => self::date($copy->checked_at),
            'error' => $copy->error,
            'details' => $copy->details,
            'created_at' => self::date($copy->created_at),
            'finished_at' => self::date($copy->finished_at),
            'next_attempt_at' => self::date($copy->next_attempt_at),
        ];
    }

    public static function date(?DateTimeInterface $date): ?string
    {
        return $date?->format(DateTimeInterface::ATOM);
    }
}
