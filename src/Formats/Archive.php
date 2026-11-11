<?php

declare(strict_types=1);

namespace OffsiteBackups\Formats;

use Generator;
use OffsiteBackups\Crypto\Keys;
use OffsiteBackups\Crypto\StreamCipher;
use OffsiteBackups\Storage\Store;

/** One object per copy: the original bytes, encrypted when the destination is. */
final readonly class Archive
{
    /**
     * @param  iterable<string>  $source
     * @param  callable(int): void  $progress  Receives the source bytes read so far.
     */
    public function write(Store $store, string $path, iterable $source, ?Keys $keys, callable $progress): Result
    {
        $sha1 = hash_init('sha1');
        $sourceBytes = 0;

        $counted = (static function () use ($source, $sha1, &$sourceBytes, $progress): Generator {
            foreach ($source as $chunk) {
                hash_update($sha1, $chunk);
                $sourceBytes += mb_strlen($chunk, '8bit');
                $progress($sourceBytes);

                yield $chunk;
            }
        })();

        $stored = $keys !== null
            ? $store->upload($path, StreamCipher::encrypt($counted, $keys))
            : $store->upload($path, $counted, 'application/x-gzip');

        return new Result($sourceBytes, hash_final($sha1), $stored, ['encrypted' => $keys !== null]);
    }

    /** @return Generator<int, string> The original bytes. */
    public function read(Store $store, string $path, ?Keys $keys): Generator
    {
        $chunks = $store->read($path);

        yield from $keys !== null ? StreamCipher::decrypt($chunks, $keys) : $chunks;
    }

    /** Reads the whole copy back. Throws when it is missing or cannot be decrypted. */
    public function check(Store $store, string $path, ?Keys $keys, string $expectedSha1): bool
    {
        $hash = hash_init('sha1');

        foreach ($this->read($store, $path, $keys) as $chunk) {
            hash_update($hash, $chunk);
        }

        return hash_equals(mb_strtolower(preg_replace('/^sha1:/', '', $expectedSha1)), hash_final($hash));
    }
}
