<?php

declare(strict_types=1);

namespace OffsiteBackups\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Server;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $server_id Null once the server is deleted.
 * @property string $server_uuid
 * @property string $server_name
 * @property int|null $node_id
 * @property string|null $backup_uuid Null for database dumps.
 * @property string $backup_name
 * @property string $kind backup|database
 * @property string|null $database_name
 * @property int $destination_id
 * @property int|null $rule_id
 * @property string $status queued|running|verified|failed
 * @property int $attempts
 * @property \Carbon\CarbonImmutable|null $next_attempt_at
 * @property \Carbon\CarbonImmutable|null $heartbeat_at
 * @property int|null $source_bytes
 * @property int $done_bytes
 * @property int $stored_bytes
 * @property string|null $checksum Wings' checksum of the backup, "sha1:<hex>".
 * @property \Carbon\CarbonImmutable|null $verified_at
 * @property \Carbon\CarbonImmutable|null $checked_at
 * @property string|null $path Object (archive) or manifest (dedup), relative to the destination root.
 * @property array<string, mixed>|null $details
 * @property string|null $error
 * @property \Carbon\CarbonImmutable|null $started_at
 * @property \Carbon\CarbonImmutable|null $finished_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property Destination $destination
 * @property Rule|null $rule
 * @property Server|null $server
 */
final class Copy extends Model
{
    public const array STATUSES = ['queued', 'running', 'verified', 'failed'];

    protected $table = 'ext_offsite_backups_copies';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'heartbeat_at' => 'immutable_datetime',
            'source_bytes' => 'integer',
            'done_bytes' => 'integer',
            'stored_bytes' => 'integer',
            'verified_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime',
            'details' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Destination, $this> */
    public function destination(): BelongsTo
    {
        return $this->belongsTo(Destination::class);
    }

    /** @return BelongsTo<Rule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    /** @return BelongsTo<Server, $this> */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class)->without('allocation');
    }
}
