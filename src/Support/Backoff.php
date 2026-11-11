<?php

declare(strict_types=1);

namespace OffsiteBackups\Support;

use Carbon\CarbonImmutable;

/**
 * Wait before the next attempt of a failed copy: 1, 4, 16, 64 ... minutes, at most a day.
 */
final readonly class Backoff
{
    public const int MAX_MINUTES = 1440;

    public static function minutes(int $attempts): int
    {
        $minutes = 1;

        for ($i = 1; $i < $attempts && $minutes < self::MAX_MINUTES; $i++) {
            $minutes *= 4;
        }

        return min(self::MAX_MINUTES, $minutes);
    }

    public static function next(int $attempts, CarbonImmutable $now): CarbonImmutable
    {
        return $now->addMinutes(self::minutes($attempts));
    }
}
