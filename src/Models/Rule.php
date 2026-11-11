<?php

declare(strict_types=1);

namespace OffsiteBackups\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property bool $enabled
 * @property int $sort
 * @property string $target_type all|nodes|eggs|servers
 * @property list<int> $target_ids
 * @property list<int> $excluded_server_ids
 * @property string $trigger backup|schedule
 * @property string|null $cron
 * @property bool $skip_idle
 * @property string $when_full skip|rotate
 * @property list<int> $destination_ids Ordered, the first is the primary copy.
 * @property string $retention newest|gfs
 * @property int|null $keep_newest
 * @property int|null $keep_daily
 * @property int|null $keep_weekly
 * @property int|null $keep_monthly
 * @property int|null $max_age_days
 * @property int|null $keep_local
 * @property bool $include_databases
 * @property \Carbon\CarbonImmutable|null $last_run_at
 * @property \Carbon\CarbonImmutable|null $next_run_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class Rule extends Model
{
    public const array TARGETS = ['all', 'nodes', 'eggs', 'servers'];

    public const array TRIGGERS = ['backup', 'schedule'];

    protected $table = 'ext_offsite_backups_rules';

    protected $guarded = ['id'];

    protected $attributes = ['target_ids' => '[]', 'excluded_server_ids' => '[]', 'destination_ids' => '[]'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'sort' => 'integer',
            'target_ids' => 'array',
            'excluded_server_ids' => 'array',
            'skip_idle' => 'boolean',
            'destination_ids' => 'array',
            'keep_newest' => 'integer',
            'keep_daily' => 'integer',
            'keep_weekly' => 'integer',
            'keep_monthly' => 'integer',
            'max_age_days' => 'integer',
            'keep_local' => 'integer',
            'include_databases' => 'boolean',
            'last_run_at' => 'immutable_datetime',
            'next_run_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
