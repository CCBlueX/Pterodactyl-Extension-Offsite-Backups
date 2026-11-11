<?php

declare(strict_types=1);

namespace OffsiteBackups\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $kind s3|sftp
 * @property string $format archive|dedup
 * @property array<string, mixed> $config s3: endpoint, region, bucket, prefix, key, secret, path_style, storage_class; sftp: host, port, username, password, private_key, key_passphrase, path
 * @property bool $encrypted
 * @property string|null $passphrase
 * @property bool $is_default
 * @property string $status ok|error|paused
 * @property string|null $status_message
 * @property \Carbon\CarbonImmutable|null $checked_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class Destination extends Model
{
    public const array KINDS = ['s3', 'sftp'];

    public const array FORMATS = ['archive', 'dedup'];

    protected $table = 'ext_offsite_backups_destinations';

    protected $guarded = ['id'];

    protected $hidden = ['config', 'passphrase'];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'encrypted' => 'boolean',
            'passphrase' => 'encrypted',
            'is_default' => 'boolean',
            'checked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<Copy, $this> */
    public function copies(): HasMany
    {
        return $this->hasMany(Copy::class);
    }

    public function isDedup(): bool
    {
        return $this->format === 'dedup';
    }
}
