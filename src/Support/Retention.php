<?php

declare(strict_types=1);

namespace OffsiteBackups\Support;

use Carbon\CarbonImmutable;

/**
 * Picks the copies a policy no longer keeps, the way restic's forget does. The newest copy always stays.
 */
final readonly class Retention
{
    /**
     * @param  list<array{id: int, at: CarbonImmutable}>  $copies
     * @return list<int> Ids to delete, oldest first.
     */
    public static function select(array $copies, Policy $policy, CarbonImmutable $now): array
    {
        if (count($copies) < 2 || $policy->isEmpty()) {
            return [];
        }

        usort($copies, static fn (array $a, array $b): int => [$b['at']->getTimestamp(), $b['id']] <=> [$a['at']->getTimestamp(), $a['id']]);

        $keep = $policy->keepsAll() ? array_fill_keys(array_column($copies, 'id'), true) : self::kept($copies, $policy);
        $cutoff = ($policy->maxAgeDays ?? 0) > 0 ? $now->subDays($policy->maxAgeDays) : null;
        $delete = [];

        foreach (array_slice($copies, 1) as $copy) {
            if (! isset($keep[$copy['id']]) || ($cutoff !== null && $copy['at']->lt($cutoff))) {
                $delete[] = $copy['id'];
            }
        }

        return array_reverse($delete);
    }

    /**
     * @param  list<array{id: int, at: CarbonImmutable}>  $copies  Newest first.
     * @return array<int, true>
     */
    private static function kept(array $copies, Policy $policy): array
    {
        $keep = [$copies[0]['id'] => true];

        foreach (array_slice($copies, 0, max(0, $policy->newest ?? 0)) as $copy) {
            $keep[$copy['id']] = true;
        }

        $buckets = [
            'Y-m-d' => $policy->daily ?? 0,
            'o-W' => $policy->weekly ?? 0,
            'Y-m' => $policy->monthly ?? 0,
        ];

        foreach ($buckets as $format => $count) {
            $last = null;

            foreach ($copies as $copy) {
                if ($count < 1) {
                    break;
                }

                $bucket = $copy['at']->format($format);

                if ($bucket !== $last) {
                    $keep[$copy['id']] = true;
                    $last = $bucket;
                    $count--;
                }
            }
        }

        return $keep;
    }
}
