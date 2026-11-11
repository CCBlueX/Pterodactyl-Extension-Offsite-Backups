<?php

declare(strict_types=1);

namespace OffsiteBackups\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use OffsiteBackups\Models\Copy;
use OffsiteBackups\Models\Rule;
use OffsiteBackups\Panel\Panel;
use OffsiteBackups\Support\Policy;
use OffsiteBackups\Support\Retention as Select;
use Throwable;

/**
 * Applies retention after a copy: the rule's policy, then the server's quota, then the rule's local backup limit.
 */
final readonly class Retention
{
    public function __construct(private Copies $copies, private Panel $panel) {}

    public static function policy(Rule $rule): Policy
    {
        return $rule->retention === 'gfs'
            ? Policy::gfs($rule->keep_daily, $rule->keep_weekly, $rule->keep_monthly, $rule->max_age_days)
            : new Policy(newest: $rule->keep_newest, maxAgeDays: $rule->max_age_days);
    }

    public function afterCopy(Copy $copy): void
    {
        if ($copy->status !== 'verified') {
            return;
        }

        $rule = $copy->rule;

        if ($rule !== null) {
            $this->applyPolicy($copy, $rule);
        }

        if ($copy->server_id !== null) {
            $this->applyAllowance($copy->server_id, $copy->server_uuid);
        }

        if ($rule !== null && $copy->kind === 'backup' && ($rule->keep_local ?? 0) > 0) {
            $this->trimLocal($copy, $rule->keep_local);
        }
    }

    /** Applies every rule's policy to all its copies (nightly, so max age also hits servers that stopped making backups). */
    public function sweep(): int
    {
        $deleted = 0;

        foreach (Rule::query()->get() as $rule) {
            $policy = self::policy($rule);

            if ($policy->isEmpty()) {
                continue;
            }

            $groups = Copy::query()->where('rule_id', $rule->id)->where('status', 'verified')
                ->toBase()->distinct()->get(['server_uuid', 'destination_id', 'kind', 'database_name']);

            foreach ($groups as $group) {
                $deleted += $this->deleteSelected($this->group($rule->id, $group->server_uuid, (int) $group->destination_id, $group->kind, $group->database_name), $policy);
            }
        }

        return $deleted;
    }

    private function applyPolicy(Copy $copy, Rule $rule): void
    {
        $policy = self::policy($rule);

        if (! $policy->isEmpty()) {
            $this->deleteSelected($this->group($rule->id, $copy->server_uuid, $copy->destination_id, $copy->kind, $copy->database_name), $policy);
        }
    }

    /** Oldest copies go first until the server is within its copy count and storage quota; its newest copy stays. */
    private function applyAllowance(int $serverId, string $serverUuid): void
    {
        $allowance = Allowance::for($serverId);
        $maxCopies = $allowance['max_copies'];
        $maxBytes = $allowance['max_storage_bytes'];

        if ($maxCopies === null && $maxBytes === null) {
            return;
        }

        $copies = Copy::query()->with('destination')->where('server_uuid', $serverUuid)->where('status', 'verified')->orderByDesc('created_at')->orderByDesc('id')->get();
        $backups = $copies->where('kind', 'backup')->count();
        $bytes = (int) $copies->sum('stored_bytes');
        // The newest backup copy and the newest dump of each database always stay.
        $newest = $copies->unique(static fn (Copy $copy): string => $copy->kind.':'.$copy->database_name)->pluck('id')->all();

        foreach ($copies->reverse() as $old) {
            if (in_array($old->id, $newest, true)) {
                continue;
            }

            $overCount = $maxCopies !== null && $old->kind === 'backup' && $backups > $maxCopies;
            $overBytes = $maxBytes !== null && $bytes > $maxBytes;

            if (! $overCount && ! $overBytes) {
                continue;
            }

            if ($this->tryDelete($old)) {
                $backups -= $old->kind === 'backup' ? 1 : 0;
                $bytes -= $old->stored_bytes;
            }
        }
    }

    /** Deletes local backups beyond the newest N that are unlocked and safely off-site. */
    private function trimLocal(Copy $copy, int $keep): void
    {
        $server = $this->panel->server((int) $copy->server_id);

        if ($server === null) {
            return;
        }

        foreach ($this->panel->localBackups($server)->slice($keep) as $backup) {
            if ($backup->is_locked || ! Copy::query()->where('backup_uuid', $backup->uuid)->where('status', 'verified')->exists()) {
                continue;
            }

            try {
                $this->panel->deleteBackup($backup);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /** @return Builder<Copy> */
    private function group(int $ruleId, string $serverUuid, int $destinationId, string $kind, ?string $database): Builder
    {
        return Copy::query()
            ->where('rule_id', $ruleId)
            ->where('server_uuid', $serverUuid)
            ->where('destination_id', $destinationId)
            ->where('kind', $kind)
            ->where('status', 'verified')
            ->when($kind === 'database', static fn (Builder $query) => $query->where('database_name', $database));
    }

    /** @param Builder<Copy> $query */
    private function deleteSelected(Builder $query, Policy $policy): int
    {
        $copies = $query->get(['id', 'created_at'])->map(static fn (Copy $copy): array => ['id' => $copy->id, 'at' => $copy->created_at])->all();
        $ids = Select::select(array_values($copies), $policy, CarbonImmutable::now());
        $deleted = 0;

        foreach (Copy::query()->with('destination')->whereIn('id', $ids)->get() as $copy) {
            $deleted += $this->tryDelete($copy) ? 1 : 0;
        }

        return $deleted;
    }

    private function tryDelete(Copy $copy): bool
    {
        try {
            $this->copies->delete($copy);

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }
}
