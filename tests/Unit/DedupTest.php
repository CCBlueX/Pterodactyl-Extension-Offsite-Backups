<?php

declare(strict_types=1);

namespace OffsiteBackups\Tests\Unit;

use OffsiteBackups\Crypto\Keys;
use OffsiteBackups\Formats\Dedup;
use OffsiteBackups\Formats\Gzip;
use OffsiteBackups\Formats\MemoryBlobIndex;
use OffsiteBackups\Formats\Paths;
use OffsiteBackups\Formats\Result;
use OffsiteBackups\Storage\MemoryStore;
use OffsiteBackups\Storage\StoreException;
use PHPUnit\Framework\TestCase;

final class DedupTest extends TestCase
{
    private const string SERVER = '0b5b8e2c-5b7e-4a43-9d34-8f1f1c0f6a11';

    private static ?string $big = null;

    public function test_copy_rebuilds_the_identical_tar(): void
    {
        $store = new MemoryStore;
        $index = new MemoryBlobIndex;
        $tar = self::world();
        $source = gzencode($tar, 6);

        $result = $this->write($store, $index, 'first', $source);

        $this->assertSame(sha1($source), $result->sha1);
        $this->assertSame(mb_strlen($source, '8bit'), $result->sourceBytes);
        $this->assertSame(['files' => 5, 'blobs' => 7, 'new_blobs' => 7, 'raw_bytes' => mb_strlen($tar, '8bit'), 'tar_sha256' => hash('sha256', $tar)], $result->details);
        $this->assertSame($index->size() + $store->size(Paths::manifest(self::SERVER, 'first')), $result->storedBytes);
        $this->assertLessThan(mb_strlen($tar, '8bit'), $index->size(), 'Text blobs are stored deflated.');

        $this->assertSame($tar, self::gunzip($this->read($store, $index, 'first')));
        $this->assertTrue((new Dedup)->check($store, $index, Paths::manifest(self::SERVER, 'first'), null, $result->details['tar_sha256']));
        $this->assertFalse((new Dedup)->check($store, $index, Paths::manifest(self::SERVER, 'first'), null, hash('sha256', 'other')));
    }

    public function test_second_copy_uploads_only_changed_blobs(): void
    {
        $store = new MemoryStore;
        $index = new MemoryBlobIndex;
        $this->write($store, $index, 'first', gzencode(self::world()));
        $writes = count($store->writes);
        $size = $index->size();

        $changed = self::world(changed: true);
        $result = $this->write($store, $index, 'second', gzencode($changed));

        $this->assertSame(1, $result->details['new_blobs']);
        $this->assertSame(7, $result->details['blobs']);
        $this->assertCount($writes + 2, $store->writes, 'One pack and one manifest.');
        $this->assertLessThan(1024 * 1024 + 4096, $index->size() - $size);
        $this->assertSame($changed, self::gunzip($this->read($store, $index, 'second')));
        $this->assertSame(self::world(), self::gunzip($this->read($store, $index, 'first')));
    }

    public function test_raw_mode_and_concatenated_gzip_members(): void
    {
        $store = new MemoryStore;
        $index = new MemoryBlobIndex;
        $first = random_bytes(1_500_000);
        $second = str_repeat("INSERT INTO `players` VALUES (1,'steve');\n", 40_000);
        $source = gzencode($first).gzencode($second)."\0\0\0\0";

        $result = $this->write($store, $index, 'dump', $source, tar: false);

        $this->assertArrayNotHasKey('files', $result->details);
        $this->assertSame(hash('sha256', $first.$second), $result->details['tar_sha256']);
        $this->assertSame($first.$second, self::gunzip($this->read($store, $index, 'dump')));
    }

    public function test_encrypted_copy_round_trips_and_hides_data(): void
    {
        $store = new MemoryStore;
        $index = new MemoryBlobIndex;
        $keys = CryptoTest::keys();
        $tar = self::world();

        $result = $this->write($store, $index, 'secret', gzencode($tar), keys: $keys);

        $this->assertSame(7, $result->details['new_blobs']);
        $this->assertSame($tar, self::gunzip($this->read($store, $index, 'secret', $keys)));

        foreach ($store->objects as $path => $object) {
            $this->assertStringNotContainsString('line 000123', $object['body'], $path);
            $this->assertStringNotContainsString(mb_substr(self::big(), 5000, 64, '8bit'), $object['body'], $path);
        }

        $other = Keys::derive('wrong', str_repeat("\3", SODIUM_CRYPTO_PWHASH_SALTBYTES), 1, 8192);
        $this->expectException(StoreException::class);
        $this->read($store, $index, 'secret', $other);
    }

    public function test_prune_keeps_live_copies_restorable(): void
    {
        $store = new MemoryStore;
        $index = new MemoryBlobIndex;
        $repository = Paths::repository(self::SERVER);
        $shared = random_bytes(1024 * 1024);

        $a = (new Tar)->file('a1', $shared)->file('a2', random_bytes(1024 * 1024))->file('a3', random_bytes(1024 * 1024))->file('a4', random_bytes(1024 * 1024))->end();
        $b = (new Tar)->file('a1', $shared)->file('b1', random_bytes(1024 * 1024))->end();
        $this->write($store, $index, 'a', gzencode($a, 1));
        $this->write($store, $index, 'b', gzencode($b, 1));
        $before = $index->size();

        $old = str_repeat('ab', 32);
        $fresh = str_repeat('cd', 32);
        $store->put(Paths::pack($repository, $old), 'left over');
        $store->objects[Paths::pack($repository, $old)]['modified'] = time() - 3 * 86400;
        $store->put(Paths::pack($repository, $fresh), 'being written');

        $stats = (new Dedup)->prune($store, $index, [Paths::manifest(self::SERVER, 'b')], null, $repository);

        $this->assertSame(1, $stats['repacked']);
        $this->assertSame(1, $stats['deleted_packs']);
        $this->assertGreaterThan(2 * 1024 * 1024, $stats['freed']);
        $this->assertLessThan($before - 2 * 1024 * 1024, $index->size());
        $this->assertNull($store->size(Paths::pack($repository, $old)));
        $this->assertNotNull($store->size(Paths::pack($repository, $fresh)));
        $this->assertSame($b, self::gunzip($this->read($store, $index, 'b')));

        try {
            $this->read($store, $index, 'a');
            $this->fail('A pruned copy could still be read.');
        } catch (StoreException) {
            $this->addToAssertionCount(1);
        }

        $stats = (new Dedup)->prune($store, $index, [], null, $repository);

        $this->assertSame(2, $stats['deleted_packs']);
        $this->assertSame(0, $index->size());
        $this->assertSame([Paths::pack($repository, $fresh)], array_column(iterator_to_array($store->list(Paths::packs($repository)), false), 'path'));
    }

    public function test_highly_compressed_input_is_inflated_in_bounded_pieces(): void
    {
        $source = gzencode(str_repeat("\0", 16 * 1024 * 1024), 9);
        $largest = 0;
        $total = 0;

        foreach (Gzip::inflate([$source]) as $piece) {
            $largest = max($largest, mb_strlen($piece, '8bit'));
            $total += mb_strlen($piece, '8bit');
        }

        $this->assertSame(16 * 1024 * 1024, $total);
        $this->assertLessThan(10 * 1024 * 1024, $largest);
    }

    /** Gzip readers must join members; gzdecode() stops after the first one. */
    private static function gunzip(string $data): string
    {
        return implode('', iterator_to_array(Gzip::inflate([$data]), false));
    }

    private static function big(): string
    {
        return self::$big ??= random_bytes(2 * 1024 * 1024 + 512 * 1024);
    }

    private static function world(bool $changed = false): string
    {
        $big = self::big();

        if ($changed) {
            $big[1024 * 1024 + 10] = $big[1024 * 1024 + 10] === 'x' ? 'y' : 'x';
        }

        $log = '';

        for ($line = 0; $line < 30_000; $line++) {
            $log .= sprintf("[12:00:%02d] line %06d: player joined the game\n", $line % 60, $line);
        }

        return (new Tar)
            ->directory('world/')
            ->file('world/region/r.0.0.mca', $big, mtime: $changed ? 1_700_000_100 : 1_700_000_000)
            ->file('logs/latest.log', $log)
            ->file('world/session.lock', '')
            ->pax(['path' => 'plugins/'.str_repeat('LongPluginName/', 10).'config.yml'])
            ->file('plugins/config.yml', 'enabled: true')
            ->file('server.jar.link', '', '2', 'server.jar')
            ->file('server.jar', mb_substr($big, 0, 300 * 1024, '8bit').'jar')
            ->end();
    }

    private function write(MemoryStore $store, MemoryBlobIndex $index, string $copy, string $source, bool $tar = true, ?Keys $keys = null): Result
    {
        return (new Dedup)->write($store, $index, Paths::manifest(self::SERVER, $copy), CryptoTest::pieces($source, 300_000), $keys, $tar, static function (): void {});
    }

    private function read(MemoryStore $store, MemoryBlobIndex $index, string $copy, ?Keys $keys = null): string
    {
        return implode('', iterator_to_array((new Dedup)->read($store, $index, Paths::manifest(self::SERVER, $copy), $keys), false));
    }
}
