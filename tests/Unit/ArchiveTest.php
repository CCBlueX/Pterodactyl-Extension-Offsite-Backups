<?php

declare(strict_types=1);

namespace OffsiteBackups\Tests\Unit;

use OffsiteBackups\Formats\Archive;
use OffsiteBackups\Formats\Paths;
use OffsiteBackups\Storage\MemoryStore;
use PHPUnit\Framework\TestCase;

final class ArchiveTest extends TestCase
{
    public function test_plain_archive_round_trips(): void
    {
        $store = new MemoryStore;
        $source = gzencode(random_bytes(3 * 1024 * 1024));
        $path = Paths::archive('server', 'backup', false);
        $seen = [];

        $result = (new Archive)->write($store, $path, CryptoTest::pieces($source, 500_000), null, function (int $bytes) use (&$seen): void {
            $seen[] = $bytes;
        });

        $this->assertSame(mb_strlen($source, '8bit'), $result->sourceBytes);
        $this->assertSame(sha1($source), $result->sha1);
        $this->assertSame(mb_strlen($source, '8bit'), $result->storedBytes);
        $this->assertSame(mb_strlen($source, '8bit'), end($seen));
        $this->assertSame($source, $store->objects['servers/server/backups/backup.tar.gz']['body']);
        $this->assertSame($source, implode('', iterator_to_array((new Archive)->read($store, $path, null), false)));
        $this->assertTrue((new Archive)->check($store, $path, null, 'sha1:'.sha1($source)));
        $this->assertFalse((new Archive)->check($store, $path, null, sha1('other')));
    }

    public function test_encrypted_archive_round_trips(): void
    {
        $store = new MemoryStore;
        $source = gzencode(str_repeat('level data ', 300_000));
        $path = Paths::archive('server', 'backup', true);
        $keys = CryptoTest::keys();

        $result = (new Archive)->write($store, $path, [$source], $keys, static function (): void {});

        $this->assertSame('servers/server/backups/backup.tar.gz.enc', $path);
        $this->assertSame(sha1($source), $result->sha1);
        $this->assertGreaterThan(mb_strlen($source, '8bit'), $result->storedBytes);
        $this->assertStringNotContainsString(mb_substr($source, 100, 64, '8bit'), $store->objects[$path]['body']);
        $this->assertSame($source, implode('', iterator_to_array((new Archive)->read($store, $path, $keys), false)));
        $this->assertTrue((new Archive)->check($store, $path, $keys, sha1($source)));
    }
}
