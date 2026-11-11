<?php

declare(strict_types=1);

namespace OffsiteBackups\Formats;

use Generator;
use OffsiteBackups\Crypto\Keys;
use OffsiteBackups\Storage\Store;
use OffsiteBackups\Storage\StoreException;

/**
 * Deduplicated copies: file contents are cut into blobs that are stored once per repository,
 * in packs, and each copy is a manifest that lists literal bytes and blob references.
 */
final readonly class Dedup
{
    private const int PIECE = 1024 * 1024;

    // Unknown pack objects younger than this may belong to a copy that is still being written.
    private const int SWEEP_GRACE = 86400;

    public function __construct(
        private int $blobSize = 1024 * 1024,
        private int $packSize = 16 * 1024 * 1024,
    ) {}

    /**
     * @param  iterable<string>  $gzipSource
     * @param  callable(int): void  $progress  Receives the source bytes read so far.
     */
    public function write(Store $store, BlobIndex $index, string $manifestPath, iterable $gzipSource, ?Keys $keys, bool $tar, callable $progress): Result
    {
        $sha1 = hash_init('sha1');
        $sha256 = hash_init('sha256');
        $sourceBytes = 0;
        $rawBytes = 0;
        $stats = ['files' => 0, 'blobs' => 0, 'new_blobs' => 0];

        $counted = (static function () use ($gzipSource, $sha1, &$sourceBytes, $progress): Generator {
            foreach ($gzipSource as $chunk) {
                hash_update($sha1, $chunk);
                $sourceBytes += mb_strlen($chunk, '8bit');
                $progress($sourceBytes);

                yield $chunk;
            }
        })();

        $manifest = new Manifest($tar);
        $packs = new PackWriter($store, $index, Paths::repositoryOf($manifestPath), $this->packSize);
        $segmenter = $tar ? new TarSegmenter : null;
        $blob = '';

        $events = static function (string $raw) use ($segmenter): iterable {
            return $segmenter !== null ? $segmenter->feed($raw) : [[TarSegmenter::DATA, $raw]];
        };

        $consume = function (iterable $events) use (&$blob, &$stats, $manifest, $packs, $index, $keys): void {
            foreach ($events as [$type, $bytes]) {
                if ($type === TarSegmenter::DATA) {
                    $blob .= $bytes;

                    if (mb_strlen($blob, '8bit') >= $this->blobSize) {
                        $blob = $this->cut($blob, $stats, $manifest, $packs, $index, $keys);
                    }

                    continue;
                }

                if ($blob !== '') {
                    $this->store($blob, $stats, $manifest, $packs, $index, $keys);
                    $blob = '';
                }

                if ($type === TarSegmenter::END) {
                    $stats['files']++;
                } else {
                    $manifest->literal($bytes);
                }
            }
        };

        foreach (Gzip::inflate($counted) as $raw) {
            hash_update($sha256, $raw);
            $rawBytes += mb_strlen($raw, '8bit');
            $consume($events($raw));
        }

        if ($segmenter !== null) {
            $consume($segmenter->finish());
        }

        if ($blob !== '') {
            $this->store($blob, $stats, $manifest, $packs, $index, $keys);
        }

        $packs->flush();

        $details = $stats + ['raw_bytes' => $rawBytes, 'tar_sha256' => hash_final($sha256)];

        if (! $tar) {
            unset($details['files']);
        }

        $manifestBytes = $store->upload($manifestPath, $manifest->finish($details, $keys));

        return new Result($sourceBytes, hash_final($sha1), $packs->written + $manifestBytes, $details);
    }

    /**
     * The original stream, gzipped again. Blobs that were stored uncompressed go into level 0
     * gzip members, everything else into level 1 members; gzip readers join the members.
     *
     * @return Generator<int, string>
     */
    public function read(Store $store, BlobIndex $index, string $manifestPath, ?Keys $keys): Generator
    {
        $context = null;
        $level = null;
        $output = '';

        foreach ($this->rebuild($store, $index, $manifestPath, $keys) as [$bytes, $compressible]) {
            $wanted = $compressible === null ? ($level ?? 1) : ($compressible ? 1 : 0);

            if ($context === null || $wanted !== $level) {
                if ($context !== null) {
                    $output .= deflate_add($context, '', ZLIB_FINISH);
                }

                $context = deflate_init(ZLIB_ENCODING_GZIP, ['level' => $wanted]);
                $level = $wanted;
            }

            $output .= deflate_add($context, $bytes, ZLIB_NO_FLUSH);

            if (mb_strlen($output, '8bit') >= self::PIECE) {
                yield $output;
                $output = '';
            }
        }

        $output .= deflate_add($context ?? deflate_init(ZLIB_ENCODING_GZIP, ['level' => 1]), '', ZLIB_FINISH);

        yield $output;
    }

    /** Rebuilds the copy and compares the sha256 of the uncompressed stream. Throws when data is missing or damaged. */
    public function check(Store $store, BlobIndex $index, string $manifestPath, ?Keys $keys, string $tarSha256): bool
    {
        $hash = hash_init('sha256');

        foreach ($this->rebuild($store, $index, $manifestPath, $keys) as [$piece]) {
            hash_update($hash, $piece);
        }

        return hash_equals(mb_strtolower(preg_replace('/^sha256:/', '', $tarSha256)), hash_final($hash));
    }

    /**
     * Drops packs no live manifest needs, repacks packs that are mostly dead and deletes pack
     * objects the index does not know.
     *
     * @param  iterable<string>  $liveManifestPaths  Every manifest that must stay restorable.
     * @param  string  $repository  Repository root (Paths::repository), so unknown packs are found even without live manifests.
     * @return array{deleted_packs: int, repacked: int, freed: int}
     */
    public function prune(Store $store, BlobIndex $index, iterable $liveManifestPaths, ?Keys $keys, string $repository = ''): array
    {
        $live = [];

        foreach ($liveManifestPaths as $path) {
            $repository = $repository !== '' ? $repository : Paths::repositoryOf($path);

            foreach (Manifest::read($store, $path, $keys) as [$type, $id]) {
                if ($type === Manifest::BLOB) {
                    $live[$id] = true;
                }
            }
        }

        $deleted = 0;
        $repacked = 0;
        $freed = 0;
        $packs = new PackWriter($store, $index, $repository, $this->packSize);
        $copied = [];

        $retire = static function (array $pack) use ($store, $index, $repository, &$freed): void {
            $index->removePack($pack['name']);
            $store->delete(Paths::pack($repository, $pack['name']));
            $freed += $pack['size'];
        };

        foreach (iterator_to_array($index->packs(), false) as $pack) {
            $blobs = array_values(array_filter($index->blobsOf($pack['name']), static fn (array $blob): bool => isset($live[$blob[0]])));
            $liveBytes = array_sum(array_column($blobs, 2));

            if ($blobs === []) {
                $retire($pack);
                $deleted++;

                continue;
            }

            if ($liveBytes * 2 >= $pack['size']) {
                continue;
            }

            // Sealed bytes move as they are; no key is needed.
            foreach ($blobs as [$id, $offset, $length]) {
                $packs->add($id, $store->readRange(Paths::pack($repository, $pack['name']), $offset, $length));
            }

            $copied[] = $pack;
            $repacked++;

            if ($packs->full() && $packs->flush()) {
                array_walk($copied, $retire);
                $copied = [];
            }
        }

        $packs->flush();
        array_walk($copied, $retire);
        $freed -= $packs->written;

        if ($repository !== '') {
            $known = array_flip(array_column(iterator_to_array($index->packs(), false), 'name'));
            $stale = [];

            foreach ($store->list(Paths::packs($repository)) as $object) {
                if (! isset($known[basename($object['path'])]) && $object['modified'] < time() - self::SWEEP_GRACE) {
                    $stale[] = $object['path'];
                    $freed += $object['size'];
                }
            }

            $store->deleteMany($stale);
            $deleted += count($stale);
        }

        return ['deleted_packs' => $deleted, 'repacked' => $repacked, 'freed' => max(0, $freed)];
    }

    /**
     * The uncompressed stream of a copy, record by record, with whether the piece compresses
     * (null for literal bytes).
     *
     * @return Generator<int, array{0: string, 1: bool|null}>
     */
    private function rebuild(Store $store, BlobIndex $index, string $manifestPath, ?Keys $keys): Generator
    {
        $repository = Paths::repositoryOf($manifestPath);
        $wanted = [];

        foreach (Manifest::read($store, $manifestPath, $keys) as [$type, $id]) {
            if ($type === Manifest::BLOB) {
                $location = $this->locate($index, $id);
                $wanted[$location['pack']] = ($wanted[$location['pack']] ?? 0) + $location['length'];
            }
        }

        $reader = new PackReader($store, $repository, $wanted);

        try {
            foreach (Manifest::read($store, $manifestPath, $keys) as [$type, $bytes, $length]) {
                if ($type === Manifest::LITERAL) {
                    yield [$bytes, null];
                } else {
                    $location = $this->locate($index, $bytes);

                    yield $this->decode($reader->blob($location['pack'], $location['offset'], $location['length']), $bytes, $length, $keys);
                }
            }
        } finally {
            $reader->close();
        }
    }

    /** @return array{pack: string, offset: int, length: int} */
    private function locate(BlobIndex $index, string $id): array
    {
        return $index->locate($id) ?? throw new StoreException('Blob '.bin2hex($id).' of this copy is missing from the destination.');
    }

    /** @return array{0: string, 1: bool} The data and whether it was stored deflated. */
    private function decode(string $stored, string $id, int $length, ?Keys $keys): array
    {
        $payload = $keys !== null ? $keys->open($stored) : $stored;
        $data = mb_substr($payload, 1, null, '8bit');
        $deflated = (ord($payload[0] ?? "\0") & 1) === 1;

        if ($deflated) {
            $data = @gzinflate($data);
        }

        if (! is_string($data) || mb_strlen($data, '8bit') !== $length || ! hash_equals($id, $keys?->id($data) ?? hash('sha256', $data, true))) {
            throw new StoreException('Blob '.bin2hex($id).' of this copy is damaged.');
        }

        return [$data, $deflated];
    }

    /**
     * Stores full blobs from the buffer and returns the rest.
     *
     * @param  array<string, int>  $stats
     */
    private function cut(string $buffer, array &$stats, Manifest $manifest, PackWriter $packs, BlobIndex $index, ?Keys $keys): string
    {
        $offset = 0;
        $length = mb_strlen($buffer, '8bit');

        while ($length - $offset >= $this->blobSize) {
            $this->store(mb_substr($buffer, $offset, $this->blobSize, '8bit'), $stats, $manifest, $packs, $index, $keys);
            $offset += $this->blobSize;
        }

        return $offset === $length ? '' : mb_substr($buffer, $offset, null, '8bit');
    }

    /** @param array<string, int> $stats */
    private function store(string $raw, array &$stats, Manifest $manifest, PackWriter $packs, BlobIndex $index, ?Keys $keys): void
    {
        $id = $keys?->id($raw) ?? hash('sha256', $raw, true);
        $length = mb_strlen($raw, '8bit');
        $manifest->blob($id, $length);
        $stats['blobs']++;

        if ($packs->holds($id) || $index->has($id)) {
            return;
        }

        $deflated = $this->compressible($raw, $length) ? gzdeflate($raw, 1) : false;
        $payload = $deflated !== false && mb_strlen($deflated, '8bit') <= $length * 0.95 ? "\x01".$deflated : "\x00".$raw;

        $packs->add($id, $keys !== null ? $keys->seal($payload) : $payload);
        $stats['new_blobs']++;

        if ($packs->full()) {
            $packs->flush();
        }
    }

    /** Deflates a few samples first, so already compressed data (region files, jars) skips the full pass. */
    private function compressible(string $raw, int $length): bool
    {
        $sample = 16 * 1024;

        if ($length < 16 * $sample) {
            return true;
        }

        $samples = '';

        for ($i = 0; $i < 4; $i++) {
            $samples .= mb_substr($raw, intdiv(($length - $sample) * $i, 3), $sample, '8bit');
        }

        return mb_strlen((string) gzdeflate($samples, 1), '8bit') <= 4 * $sample * 0.95;
    }
}
