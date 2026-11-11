<?php

declare(strict_types=1);

namespace OffsiteBackups\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $copy_id
 * @property int $server_id
 * @property string $backup_uuid
 * @property string|null $revived trashed|created: the backup row was brought back for Wings' report and goes again afterwards.
 * @property int|null $user_id
 * @property bool $truncate
 * @property string $status running|done|failed
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property Copy|null $copy
 */
final class Restore extends Model
{
    protected $table = 'ext_offsite_backups_restores';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['truncate' => 'boolean', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Copy, $this> */
    public function copy(): BelongsTo
    {
        return $this->belongsTo(Copy::class);
    }
}
