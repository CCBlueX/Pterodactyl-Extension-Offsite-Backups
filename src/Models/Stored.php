<?php

declare(strict_types=1);

namespace OffsiteBackups\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A backup Wings uploaded straight to an S3 destination.
 *
 * @property string $backup_uuid
 * @property int $destination_id
 * @property Destination $destination
 */
final class Stored extends Model
{
    public const null UPDATED_AT = null;

    public $incrementing = false;

    protected $table = 'ext_offsite_backups_stored';

    protected $primaryKey = 'backup_uuid';

    protected $keyType = 'string';

    protected $guarded = [];

    /** @return BelongsTo<Destination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }
}
