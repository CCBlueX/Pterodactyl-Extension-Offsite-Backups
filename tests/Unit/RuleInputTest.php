<?php

declare(strict_types=1);

namespace OffsiteBackups\Tests\Unit;

use Carbon\CarbonImmutable;
use OffsiteBackups\Http\Requests\RuleInput;
use OffsiteBackups\Models\Rule;
use OffsiteBackups\Services\Usage;
use PHPUnit\Framework\TestCase;

final class RuleInputTest extends TestCase
{
    private const array BASE = [
        'name' => ' Nightly ',
        'enabled' => true,
        'target_type' => 'nodes',
        'target_ids' => ['2', 2, 3],
        'trigger' => 'schedule',
        'cron' => ' 0  4 * * * ',
        'skip_idle' => true,
        'when_full' => 'rotate',
        'destination_ids' => [4],
        'retention' => 'gfs',
        'keep_newest' => 5,
        'keep_daily' => 7,
        'keep_weekly' => null,
        'keep_monthly' => 6,
    ];

    public function test_normalise_casts_and_clears_unused_fields(): void
    {
        $rule = RuleInput::normalise(self::BASE);

        $this->assertSame('Nightly', $rule['name']);
        $this->assertSame([2, 3], $rule['target_ids']);
        $this->assertSame('0 4 * * *', $rule['cron']);
        $this->assertTrue($rule['skip_idle']);
        $this->assertSame('rotate', $rule['when_full']);
        $this->assertNull($rule['keep_newest']);
        $this->assertSame(7, $rule['keep_daily']);
        $this->assertSame(6, $rule['keep_monthly']);
        $this->assertSame([], RuleInput::problems($rule));

        $backup = RuleInput::normalise([...self::BASE, 'trigger' => 'backup', 'retention' => 'newest', 'target_type' => 'all']);
        $this->assertNull($backup['cron']);
        $this->assertFalse($backup['skip_idle']);
        $this->assertSame('skip', $backup['when_full']);
        $this->assertSame(5, $backup['keep_newest']);
        $this->assertNull($backup['keep_daily']);
        $this->assertSame([], $backup['target_ids']);
    }

    public function test_problems(): void
    {
        $problems = RuleInput::problems(RuleInput::normalise([
            ...self::BASE,
            'name' => '',
            'target_ids' => [],
            'cron' => 'every night',
            'destination_ids' => [],
            'keep_daily' => null,
            'keep_monthly' => null,
            'max_age_days' => 5000,
        ]));

        $this->assertSame(['name', 'target_ids', 'cron', 'destination_ids', 'keep_daily', 'max_age_days'], array_keys($problems));
        $this->assertArrayHasKey('cron', RuleInput::problems(RuleInput::normalise([...self::BASE, 'cron' => null])));
    }

    public function test_next_run_uses_the_panel_timezone(): void
    {
        $now = CarbonImmutable::parse('2026-11-11 05:00:00', 'UTC');

        $this->assertSame('2026-11-12T04:00:00+00:00', RuleInput::nextRun('0 4 * * *', $now, 'UTC')->toAtomString());
        $this->assertSame('2026-11-12T04:00:00+01:00', RuleInput::nextRun('0 4 * * *', $now, 'Europe/Berlin')->toAtomString());
        $this->assertSame('2026-11-11T05:15:00+00:00', RuleInput::nextRun('*/15 * * * *', $now, 'UTC')->toAtomString());
    }

    public function test_policy_text(): void
    {
        $this->assertSame('7 daily, 6 monthly, up to 90 days', Usage::policy(self::rule(['retention' => 'gfs', 'keep_daily' => 7, 'keep_monthly' => 6, 'max_age_days' => 90])));
        $this->assertSame('Newest 3', Usage::policy(self::rule(['retention' => 'newest', 'keep_newest' => 3])));
        $this->assertSame('All copies', Usage::policy(self::rule(['retention' => 'newest'])));
    }

    /** @param array<string, mixed> $attributes */
    private static function rule(array $attributes): Rule
    {
        return (new Rule())->setRawAttributes($attributes);
    }
}
