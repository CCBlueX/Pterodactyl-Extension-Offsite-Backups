<?php

declare(strict_types=1);

namespace OffsiteBackups\Tests\Unit;

use Carbon\CarbonImmutable;
use OffsiteBackups\Support\Policy;
use OffsiteBackups\Support\Retention;
use PHPUnit\Framework\TestCase;

final class RetentionTest extends TestCase
{
    public function test_keeps_the_newest_n(): void
    {
        $copies = $this->copies([1 => '2026-11-01 10:00', 2 => '2026-11-02 10:00', 3 => '2026-11-03 10:00', 4 => '2026-11-04 10:00', 5 => '2026-11-05 10:00']);

        $this->assertSame([1, 2], Retention::select($copies, Policy::newest(3), $this->now()));
        $this->assertSame([], Retention::select($copies, Policy::newest(10), $this->now()));
    }

    public function test_input_order_does_not_matter(): void
    {
        $copies = array_reverse($this->copies([1 => '2026-11-01 10:00', 2 => '2026-11-02 10:00', 3 => '2026-11-03 10:00']));

        $this->assertSame([1], Retention::select($copies, Policy::newest(2), $this->now()));
    }

    public function test_daily_keeps_the_newest_copy_of_each_day(): void
    {
        $copies = $this->copies([
            1 => '2026-11-08 02:00',
            2 => '2026-11-08 23:00',
            3 => '2026-11-09 06:00',
            4 => '2026-11-10 01:00',
            5 => '2026-11-10 12:00',
            6 => '2026-11-10 18:00',
        ]);

        $this->assertSame([1, 4, 5], Retention::select($copies, Policy::gfs(3, null, null), $this->now()));
        $this->assertSame([1, 2, 3, 4, 5], Retention::select($copies, Policy::gfs(1, null, null), $this->now()));
    }

    public function test_daily_counts_days_with_copies_not_calendar_days(): void
    {
        $copies = $this->copies([1 => '2026-10-01 10:00', 2 => '2026-10-20 10:00', 3 => '2026-11-10 10:00']);

        $this->assertSame([], Retention::select($copies, Policy::gfs(3, null, null), $this->now()));
    }

    public function test_weekly_uses_iso_weeks_across_month_and_year_ends(): void
    {
        // 2026-12-28 (Mon) to 2027-01-03 (Sun) is ISO week 2026-W53; 2027-01-04 starts 2027-W01.
        $copies = $this->copies([
            1 => '2026-12-21 10:00',
            2 => '2026-12-28 10:00',
            3 => '2026-12-31 10:00',
            4 => '2027-01-02 10:00',
            5 => '2027-01-04 10:00',
        ]);
        $now = CarbonImmutable::parse('2027-01-05 00:00', 'UTC');

        $this->assertSame([2, 3], Retention::select($copies, Policy::gfs(null, 3, null), $now));
        $this->assertSame([1, 2, 3], Retention::select($copies, Policy::gfs(null, 2, null), $now));
    }

    public function test_weekly_bucket_spans_a_month_boundary(): void
    {
        // 2026-10-26 (Mon) to 2026-11-01 (Sun) is one ISO week.
        $copies = $this->copies([1 => '2026-10-26 10:00', 2 => '2026-10-31 10:00', 3 => '2026-11-01 10:00', 4 => '2026-11-02 10:00']);

        $this->assertSame([1, 2], Retention::select($copies, Policy::gfs(null, 2, null), $this->now()));
    }

    public function test_monthly_keeps_the_newest_copy_of_each_month(): void
    {
        $copies = $this->copies([
            1 => '2026-08-15 10:00',
            2 => '2026-09-30 23:59',
            3 => '2026-10-01 00:01',
            4 => '2026-10-31 23:00',
            5 => '2026-11-01 01:00',
        ]);

        $this->assertSame([1, 3], Retention::select($copies, Policy::gfs(null, null, 3), $this->now()));
    }

    public function test_buckets_combine_like_restic(): void
    {
        $copies = [];

        // One copy a day from 2026-09-01 to 2026-11-10.
        for ($day = CarbonImmutable::parse('2026-09-01 03:00', 'UTC'), $id = 1; $day->lte($this->now()); $day = $day->addDay(), $id++) {
            $copies[] = ['id' => $id, 'at' => $day];
        }

        $delete = Retention::select($copies, Policy::gfs(7, 4, 3), $this->now());
        $kept = array_values(array_diff(array_column($copies, 'id'), $delete));
        $keptDays = array_map(static fn (int $id): string => $copies[$id - 1]['at']->format('Y-m-d'), $kept);

        // Each bucket counts on its own from the newest copy, as restic does.
        $this->assertSame([
            '2026-09-30', // monthly
            '2026-10-25', // weekly
            '2026-10-31', // monthly
            '2026-11-01', // weekly
            '2026-11-04', '2026-11-05', '2026-11-06', '2026-11-07', // daily
            '2026-11-08', // daily, weekly
            '2026-11-09', '2026-11-10',
        ], $keptDays);
    }

    public function test_max_age_deletes_old_copies_on_top_of_counts(): void
    {
        $copies = $this->copies([1 => '2026-10-01 10:00', 2 => '2026-11-01 10:00', 3 => '2026-11-09 10:00', 4 => '2026-11-10 10:00']);

        $this->assertSame([1, 2], Retention::select($copies, Policy::newest(10, 7), $this->now()));
        $this->assertSame([1, 2], Retention::select($copies, new Policy(maxAgeDays: 7), $this->now()));
    }

    public function test_the_newest_copy_is_never_deleted(): void
    {
        $copies = $this->copies([1 => '2026-01-01 10:00', 2 => '2026-01-02 10:00', 3 => '2026-01-03 10:00']);

        $this->assertSame([1, 2], Retention::select($copies, new Policy(maxAgeDays: 1), $this->now()));
        $this->assertSame([1, 2], Retention::select($copies, Policy::newest(1, 1), $this->now()));
        $this->assertSame([], Retention::select([['id' => 9, 'at' => CarbonImmutable::parse('2020-01-01', 'UTC')]], new Policy(maxAgeDays: 1), $this->now()));
    }

    public function test_an_empty_policy_keeps_everything(): void
    {
        $copies = $this->copies([1 => '2026-01-01 10:00', 2 => '2026-01-02 10:00']);

        $this->assertSame([], Retention::select($copies, new Policy(), $this->now()));
        $this->assertTrue((new Policy())->isEmpty());
        $this->assertSame('3 daily, 2 weekly, max 30 days', Policy::gfs(3, 2, null, 30)->describe());
    }

    /**
     * @param  array<int, string>  $times
     * @return list<array{id: int, at: CarbonImmutable}>
     */
    private function copies(array $times): array
    {
        return array_map(static fn (int $id, string $at): array => ['id' => $id, 'at' => CarbonImmutable::parse($at, 'UTC')], array_keys($times), $times);
    }

    private function now(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-11-10 20:00', 'UTC');
    }
}
