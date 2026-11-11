<?php

declare(strict_types=1);

namespace OffsiteBackups\Tests\Unit;

use OffsiteBackups\Formats\TarSegmenter;
use PharData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TarSegmenterTest extends TestCase
{
    /** @return array<string, array{0: list<int>}> */
    public static function chunkings(): array
    {
        return [
            'single bytes and odd sizes' => [[1, 7, 511, 513, 4096, 100_000]],
            'one block' => [[512]],
            'big pieces' => [[1024 * 1024]],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function tarFormats(): array
    {
        return ['gnu' => ['gnu'], 'pax' => ['pax'], 'ustar' => ['ustar']];
    }

    #[DataProvider('chunkings')]
    public function test_hand_built_archive_round_trips(array $sizes): void
    {
        $big = random_bytes(1024 * 1024 + 4321);
        $longPath = 'world/'.str_repeat('nested-folder/', 12).'level.dat';

        $tar = (new Tar)
            ->directory('world/')
            ->file('world/big.bin', $big)
            ->file('world/empty.txt', '')
            ->pax(['path' => $longPath, 'mtime' => '1700000000.5'])
            ->file('ignored-name', 'pax named file')
            ->file('world/link', '', '2', 'big.bin')
            ->longName($longPath.'.gnu')
            ->file('short', 'gnu named file')
            ->file('base256.txt', 'size in base-256', base256: true)
            ->file('contiguous.bin', 'type 7', '7')
            ->end();

        [$files, $stream] = $this->segment($tar, $sizes);

        $this->assertSame($tar, $stream);
        $this->assertSame([$big, '', 'pax named file', 'gnu named file', 'size in base-256', 'type 7'], $files);
    }

    public function test_pax_size_overrides_the_header_size(): void
    {
        $content = str_repeat('x', 1500);
        $header = Tar::header('huge.bin', 0, '0', '', false, 1_700_000_000);
        $tar = (new Tar)->pax(['size' => 1500])->bytes($header.$content.str_repeat("\0", 548))->file('after.txt', 'after')->end();

        [$files, $stream] = $this->segment($tar, [700]);

        $this->assertSame($tar, $stream);
        $this->assertSame([$content, 'after'], $files);
    }

    public function test_garbage_after_a_broken_header_is_kept_as_is(): void
    {
        $tar = (new Tar)->file('a.txt', 'hello')->end();
        $broken = mb_substr($tar, 0, 1024, '8bit').str_repeat('garbage!', 100).random_bytes(3000);

        [$files, $stream] = $this->segment($broken, [333]);

        $this->assertSame($broken, $stream);
        $this->assertSame(['hello'], $files);
    }

    public function test_truncated_archive_round_trips(): void
    {
        $tar = (new Tar)->file('a.bin', random_bytes(5000))->end();
        $cut = mb_substr($tar, 0, 3000, '8bit');

        [$files, $stream] = $this->segment($cut, [1000]);

        $this->assertSame($cut, $stream);
        $this->assertCount(1, $files);
    }

    public function test_phar_built_archive_round_trips(): void
    {
        $folder = sys_get_temp_dir().'/ofs-tar-'.bin2hex(random_bytes(4));
        mkdir($folder);
        $path = "{$folder}/test.tar";

        try {
            $phar = new PharData($path);
            $phar->addFromString('server.properties', "motd=hello\n");
            $phar->addFromString('world/region/r.0.0.mca', $region = random_bytes(2 * 1024 * 1024 + 17));
            $phar->addFromString('empty.log', '');
            unset($phar);

            $tar = file_get_contents($path);
            [$files, $stream] = $this->segment($tar, [65536]);

            $this->assertSame($tar, $stream);
            $this->assertContains($region, $files);
            $this->assertContains('', $files);
            $this->assertCount(3, $files);
        } finally {
            @unlink($path);
            @rmdir($folder);
        }
    }

    #[DataProvider('tarFormats')]
    public function test_tar_tool_archives_round_trip(string $format): void
    {
        if (mb_trim((string) shell_exec('command -v tar')) === '') {
            $this->markTestSkipped('tar is not installed.');
        }

        $folder = sys_get_temp_dir().'/ofs-tar-'.bin2hex(random_bytes(4));
        $long = $format === 'ustar' ? 'plugins/Long' : 'plugins/'.str_repeat('VeryLongPluginFolderName', 6);
        mkdir("{$folder}/src/{$long}", 0777, true);
        file_put_contents("{$folder}/src/{$long}/config.yml", 'enabled: true');
        file_put_contents("{$folder}/src/big.jar", $big = random_bytes(3 * 1024 * 1024 + 99));
        file_put_contents("{$folder}/src/empty", '');
        symlink('big.jar', "{$folder}/src/link.jar");

        try {
            exec('tar --format='.$format.' -C '.escapeshellarg("{$folder}/src").' -cf '.escapeshellarg("{$folder}/out.tar").' . 2>&1', $output, $code);
            $this->assertSame(0, $code, implode("\n", $output));

            $tar = file_get_contents("{$folder}/out.tar");
            [$files, $stream] = $this->segment($tar, [12345, 1024 * 1024]);

            $this->assertSame($tar, $stream);
            $this->assertContains($big, $files);
            $this->assertContains('enabled: true', $files);
            $this->assertCount(3, $files);
        } finally {
            exec('rm -rf '.escapeshellarg($folder));
        }
    }

    /**
     * @param  list<int>  $sizes  Chunk sizes, used in turn.
     * @return array{0: list<string>, 1: string} File contents and the reassembled stream.
     */
    private function segment(string $tar, array $sizes): array
    {
        $segmenter = new TarSegmenter;
        $files = [];
        $current = '';
        $stream = '';
        $events = [];

        for ($offset = 0, $i = 0, $length = mb_strlen($tar, '8bit'); $offset < $length; $i++) {
            $size = $sizes[$i % count($sizes)];
            $events[] = $segmenter->feed(mb_substr($tar, $offset, $size, '8bit'));
            $offset += $size;

            foreach (array_pop($events) as [$type, $bytes]) {
                $this->apply($type, $bytes, $files, $current, $stream);
            }
        }

        foreach ($segmenter->finish() as [$type, $bytes]) {
            $this->apply($type, $bytes, $files, $current, $stream);
        }

        return [$files, $stream];
    }

    /** @param list<string> $files */
    private function apply(int $type, string $bytes, array &$files, string &$current, string &$stream): void
    {
        if ($type === TarSegmenter::END) {
            $files[] = $current;
            $current = '';

            return;
        }

        $stream .= $bytes;

        if ($type === TarSegmenter::DATA) {
            $current .= $bytes;
        }
    }
}
