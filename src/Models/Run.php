<?php

declare(strict_types=1);

namespace OffsiteBackups\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pterodactyl\Models\Server;

/**
 * A backup a scheduled rule asked for.
 *
 * @property int $id
 * @property int $rule_id
 * @property int $server_id
 * @property string|null $backup_uuid
 * @property string $status planned|started|done|skipped|failed
 * @property string|null $message
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property Rule $rule
 * @property Server $server
 */
final class Run extends Model
{
    protected $table = 'ext_offsite_backups_runs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
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
