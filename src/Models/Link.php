<?php

declare(strict_types=1);

namespace OffsiteBackups\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single-use link that streams a copy, to a browser (download) or to Wings (restore).
 *
 * @property int $id
 * @property string $token sha256 of the secret in the URL.
 * @property int $copy_id
 * @property string $purpose download|restore
 * @property int|null $user_id
 * @property \Carbon\CarbonImmutable $expires_at
 * @property \Carbon\CarbonImmutable|null $used_at
 * @property Copy $copy
 */
final class Link extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'ext_offsite_backups_links';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Copy, $this> */
    public function copy(): BelongsTo
    {
        return $this->belongsTo(Copy::class);
    }
}
