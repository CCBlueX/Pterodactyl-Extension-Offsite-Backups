<?php

declare(strict_types=1);

namespace OffsiteBackups\Tests\Unit;

use OffsiteBackups\Services\Allowance;
use PHPUnit\Framework\TestCase;

final class AllowanceTest extends TestCase
{
    private const array STORED = ['enabled' => true, 'max_copies' => 5, 'max_storage_gb' => 10, 'storage' => 's3', 'storage_destination_id' => 2];

    public function test_partial_input_keeps_the_other_values(): void
    {
        $this->assertSame([...self::STORED, 'max_copies' => 7], Allowance::normalise(self::STORED, ['max_copies' => '7', 'unknown' => 1]));
        $this->assertSame([...self::STORED, 'enabled' => null], Allowance::normalise(self::STORED, ['enabled' => null]));
    }

    public function test_the_destination_only_sticks_with_s3_storage(): void
    {
        $this->assertSame([...self::STORED, 'storage' => 'node', 'storage_destination_id' => null], Allowance::normalise(self::STORED, ['storage' => 'node']));
        $this->assertSame('default', Allowance::normalise(self::STORED, ['storage' => 'tape'])['storage']);
    }
}
