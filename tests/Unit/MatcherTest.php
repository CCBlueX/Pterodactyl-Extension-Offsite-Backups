<?php

declare(strict_types=1);

namespace OffsiteBackups\Tests\Unit;

use OffsiteBackups\Rules\Matcher;
use PHPUnit\Framework\TestCase;

final class MatcherTest extends TestCase
{
    public function test_all_matches_every_server_but_the_excluded(): void
    {
        $this->assertTrue(Matcher::targets('all', [], [], 5, 1, 2));
        $this->assertFalse(Matcher::targets('all', [], [5], 5, 1, 2));
        $this->assertFalse(Matcher::targets('all', [], ['5'], 5, 1, 2));
    }

    public function test_nodes_eggs_and_servers_compare_the_right_id(): void
    {
        $this->assertTrue(Matcher::targets('nodes', [1, 3], [], 5, 3, 9));
        $this->assertFalse(Matcher::targets('nodes', [1], [], 5, 3, 9));
        $this->assertTrue(Matcher::targets('eggs', ['9'], [], 5, 3, 9));
        $this->assertFalse(Matcher::targets('eggs', [3], [], 5, 3, 9));
        $this->assertTrue(Matcher::targets('servers', [5], [], 5, 3, 9));
        $this->assertFalse(Matcher::targets('servers', [3, 9], [], 5, 3, 9));
    }

    public function test_exclusions_win_and_empty_or_unknown_targets_match_nothing(): void
    {
        $this->assertFalse(Matcher::targets('nodes', [3], [5], 5, 3, 9));
        $this->assertFalse(Matcher::targets('servers', [], [], 5, 3, 9));
        $this->assertFalse(Matcher::targets('locations', [3], [], 5, 3, 9));
    }
}
