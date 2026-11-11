<?php

declare(strict_types=1);

namespace OffsiteBackups\Http\Presenters;

use Illuminate\Database\Eloquent\Builder;
use OffsiteBackups\Http\Requests\DestinationInput;
use OffsiteBackups\Models\Destination;

final readonly class DestinationPresenter
{
    /** @return Builder<Destination> */
    public static function query(): Builder
    {
        return Destination::query()
            ->withCount('copies')
            ->withSum(['copies' => static fn ($query) => $query->where('status', 'verified')], 'stored_bytes')
            ->orderByDesc('is_default')
            ->orderBy('name');
    }

    /** @return array<string, mixed> */
    public static function present(Destination $destination): array
    {
        if (! array_key_exists('copies_count', $destination->getAttributes())) {
            $destination->loadCount('copies')->loadSum(['copies' => static fn ($query) => $query->where('status', 'verified')], 'stored_bytes');
        }

        return [
            'id' => $destination->id,
            'name' => $destination->name,
            'kind' => $destination->kind,
            'format' => $destination->format,
            'encrypted' => $destination->encrypted,
            'is_default' => $destination->is_default,
            'status' => $destination->status,
            'status_message' => $destination->status_message,
            'checked_at' => CopyPresenter::date($destination->checked_at),
            'copies' => (int) $destination->copies_count,
            'stored_bytes' => (int) ($destination->copies_sum_stored_bytes ?? 0),
            'config' => DestinationInput::shown($destination->kind, (array) $destination->config),
        ];
    }
}
