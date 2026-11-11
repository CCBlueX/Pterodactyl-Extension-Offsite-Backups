<?php

declare(strict_types=1);

namespace OffsiteBackups\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The deduplicated store of one server on one destination.
 *
 * @property int $id
 * @property int $destination_id
 * @property string $server_uuid
 * @property int $size Bytes in packs.
 * @property bool $dirty Copies were deleted since the last prune.
 * @property \Carbon\CarbonImmutable|null $reading_until A restore or download streams from it until then.
 * @property \Carbon\CarbonImmutable|null $pruned_at
 * @property Destination $destination
 */
final class Repository extends Model
{
    protected $table = 'ext_offsite_backups_repositories';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'dirty' => 'boolean',
            'reading_until' => 'immutable_datetime',
            'pruned_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Destination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    /** @return HasMany<Pack, $this> */
    public function packs(): HasMany
    {
        return $this->hasMany(Pack::class);
    }
}
