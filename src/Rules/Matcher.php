<?php

declare(strict_types=1);

namespace OffsiteBackups\Rules;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use OffsiteBackups\Models\Rule;
use Pterodactyl\Models\Server;

/**
 * Which servers a rule applies to: all, some nodes, some eggs or some servers, minus exclusions.
 */
final readonly class Matcher
{
    /**
     * @param  list<int>  $ids
     * @param  list<int>  $excluded
     */
    public static function targets(string $type, array $ids, array $excluded, int $serverId, int $nodeId, int $eggId): bool
    {
        if (in_array($serverId, self::ints($excluded), true)) {
            return false;
        }

        return match ($type) {
            'all' => true,
            'nodes' => in_array($nodeId, self::ints($ids), true),
            'eggs' => in_array($eggId, self::ints($ids), true),
            'servers' => in_array($serverId, self::ints($ids), true),
            default => false,
        };
    }

    public static function matches(Rule $rule, Server $server): bool
    {
        return self::targets($rule->target_type, $rule->target_ids ?? [], $rule->excluded_server_ids ?? [], (int) $server->id, (int) $server->node_id, (int) $server->egg_id);
    }

    /** @return Builder<Server> */
    public static function servers(Rule $rule): Builder
    {
        $query = Server::query();
        $ids = self::ints($rule->target_ids ?? []);
        $column = match ($rule->target_type) {
            'nodes' => 'node_id',
            'eggs' => 'egg_id',
            'servers' => 'id',
            default => null,
        };

        if ($rule->target_type !== 'all') {
            $query->whereIn($column !== null ? "servers.{$column}" : 'servers.id', $column !== null ? $ids : []);
        }

        $excluded = self::ints($rule->excluded_server_ids ?? []);
        if ($excluded !== []) {
            $query->whereNotIn('servers.id', $excluded);
        }

        return $query;
    }

    /**
     * Enabled rules that apply to the server, in rule order.
     *
     * @return Collection<int, Rule>
     */
    public static function rulesFor(Server $server, ?string $trigger = null): Collection
    {
        return Rule::query()
            ->where('enabled', true)
            ->when($trigger !== null, static fn (Builder $query) => $query->where('trigger', $trigger))
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->filter(static fn (Rule $rule): bool => self::matches($rule, $server))
            ->values();
    }

    /**
     * @param  array<mixed>  $values
     * @return list<int>
     */
    private static function ints(array $values): array
    {
        return array_values(array_map(intval(...), array_filter($values, is_numeric(...))));
    }
}
