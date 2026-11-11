<?php

declare(strict_types=1);

namespace OffsiteBackups\Http\Presenters;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use OffsiteBackups\Models\Destination;
use OffsiteBackups\Models\Rule;
use OffsiteBackups\Rules\Matcher;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;

final readonly class RulePresenter
{
    /**
     * @param  iterable<Rule>  $rules
     * @return list<array<string, mixed>>
     */
    public static function many(iterable $rules): array
    {
        $rules = collect($rules);
        $ids = static fn (string $type): array => $rules
            ->filter(static fn (Rule $rule): bool => $rule->target_type === $type)
            ->flatMap(static fn (Rule $rule): array => $rule->target_ids ?? [])
            ->all();

        $serverIds = [...$ids('servers'), ...$rules->flatMap(static fn (Rule $rule): array => $rule->excluded_server_ids ?? [])->all()];
        $names = [
            'nodes' => self::names(Node::query()->toBase(), $ids('nodes')),
            'eggs' => self::names(Egg::query()->toBase(), $ids('eggs')),
            'servers' => self::names(Server::query()->toBase(), $serverIds),
        ];
        $destinations = self::names(Destination::query()->toBase(), $rules->flatMap(static fn (Rule $rule): array => $rule->destination_ids ?? [])->all());
        $pick = static fn (Collection $known, array $wanted): array => array_values(array_map(
            static fn (int $id): array => ['id' => $id, 'name' => $known[$id] ?? "#{$id}"],
            array_map(intval(...), $wanted),
        ));

        return $rules->map(static fn (Rule $rule): array => [
            'id' => $rule->id,
            'name' => $rule->name,
            'enabled' => $rule->enabled,
            'sort' => $rule->sort,
            'target_type' => $rule->target_type,
            'target_ids' => $rule->target_ids ?? [],
            'excluded_server_ids' => $rule->excluded_server_ids ?? [],
            'trigger' => $rule->trigger,
            'cron' => $rule->cron,
            'skip_idle' => $rule->skip_idle,
            'when_full' => $rule->when_full,
            'destination_ids' => $rule->destination_ids ?? [],
            'retention' => $rule->retention,
            'keep_newest' => $rule->keep_newest,
            'keep_daily' => $rule->keep_daily,
            'keep_weekly' => $rule->keep_weekly,
            'keep_monthly' => $rule->keep_monthly,
            'max_age_days' => $rule->max_age_days,
            'keep_local' => $rule->keep_local,
            'include_databases' => $rule->include_databases,
            'last_run_at' => CopyPresenter::date($rule->last_run_at),
            'next_run_at' => CopyPresenter::date($rule->next_run_at),
            'created_at' => CopyPresenter::date($rule->created_at),
            'updated_at' => CopyPresenter::date($rule->updated_at),
            'targets' => $rule->target_type === 'all' ? [] : $pick($names[$rule->target_type] ?? collect(), $rule->target_ids ?? []),
            'excluded' => $pick($names['servers'], $rule->excluded_server_ids ?? []),
            'destinations' => $pick($destinations, $rule->destination_ids ?? []),
            'matches' => Matcher::servers($rule)->count(),
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    public static function one(Rule $rule): array
    {
        return self::many([$rule])[0];
    }

    /**
     * @param  list<mixed>  $ids
     * @return Collection<int, string>
     */
    private static function names(Builder $query, array $ids): Collection
    {
        $ids = array_values(array_unique(array_map(intval(...), array_filter($ids, is_numeric(...)))));

        return $ids === [] ? collect() : $query->whereIn('id', $ids)->pluck('name', 'id');
    }
}
