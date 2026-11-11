<?php

declare(strict_types=1);

namespace OffsiteBackups\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $repository_id
 * @property string $name 64 hex characters.
 * @property int $size
 */
final class Pack extends Model
{
    public const null UPDATED_AT = null;

    protected $table = 'ext_offsite_backups_packs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['size' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
