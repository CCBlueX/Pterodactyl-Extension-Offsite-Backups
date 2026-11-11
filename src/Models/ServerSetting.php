<?php

declare(strict_types=1);

namespace OffsiteBackups\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $server_id
 * @property bool|null $enabled Null follows the panel default.
 * @property int|null $max_copies
 * @property int|null $max_storage_gb
 * @property string $storage default|node|s3
 * @property int|null $storage_destination_id
 * @property Destination|null $storageDestination
 */
final class ServerSetting extends Model
{
    public const array STORAGE = ['default', 'node', 's3'];

    public $incrementing = false;

    protected $table = 'ext_offsite_backups_servers';

    protected $primaryKey = 'server_id';

    protected $guarded = [];

    protected $attributes = ['storage' => 'default'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'max_copies' => 'integer',
            'max_storage_gb' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Destination, $this> */
    public function storageDestination(): BelongsTo
    {
        return $this->belongsTo(Destination::class, 'storage_destination_id');
    }
}
