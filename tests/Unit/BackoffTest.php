<?php

declare(strict_types=1);

namespace OffsiteBackups\Tests\Unit;

use Carbon\CarbonImmutable;
use OffsiteBackups\Support\Backoff;
use PHPUnit\Framework\TestCase;

final class BackoffTest extends TestCase
{
    public function test_waits_grow_by_four(): void
    {
        $this->assertSame([1, 4, 16, 64, 256, 1024], array_map(Backoff::minutes(...), [1, 2, 3, 4, 5, 6]));
    }

    public function test_waits_stop_at_a_day(): void
    {
        $this->assertSame(1440, Backoff::minutes(7));
        $this->assertSame(1440, Backoff::minutes(10));
        $this->assertSame(1, Backoff::minutes(0));
    }

    public function test_next_attempt_time(): void
    {
        $now = CarbonImmutable::parse('2026-11-10 20:00', 'UTC');

        $this->assertSame('2026-11-10 20:16', Backoff::next(3, $now)->format('Y-m-d H:i'));
    }
}
