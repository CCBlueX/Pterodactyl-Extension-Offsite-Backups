<?php

declare(strict_types=1);

namespace OffsiteBackups\Formats;

use Generator;
use OffsiteBackups\Storage\StoreException;

/** Streaming gzip in both directions with bounded memory. */
final readonly class Gzip
{
    private const int PIECE = 1024 * 1024;

    // Small input slices bound the output of a single inflate call, even for highly compressed data.
    private const int SLICE = 8 * 1024;

    /**
     * Inflates a gzip stream, including concatenated members.
     *
     * @param  iterable<string>  $chunks
     * @return Generator<int, string> About 1 MiB per piece.
     */
    public static function inflate(iterable $chunks, int $encoding = ZLIB_ENCODING_GZIP): Generator
    {
        $context = inflate_init($encoding);
        $fed = 0;
        $ended = false;
        $started = false;
        $output = '';

        foreach ($chunks as $chunk) {
            $length = mb_strlen($chunk, '8bit');
            $offset = 0;

            while ($offset < $length) {
                if ($ended) {
                    if ($encoding !== ZLIB_ENCODING_GZIP || $chunk[$offset] !== "\x1f") {
                        if (strspn($chunk, "\0", $offset) !== $length - $offset) {
                            throw new StoreException('The archive has unexpected data after its end.');
                        }

                        break;
                    }

                    $context = inflate_init($encoding);
                    $fed = 0;
                    $ended = false;
                }

                $slice = mb_substr($chunk, $offset, self::SLICE, '8bit');
                $data = @inflate_add($context, $slice, ZLIB_SYNC_FLUSH);

                if ($data === false) {
                    throw new StoreException('The archive is not valid gzip data.');
                }

                $started = true;
                $output .= $data;

                if (inflate_get_status($context) === ZLIB_STREAM_END) {
                    $offset += inflate_get_read_len($context) - $fed;
                    $ended = true;
                } else {
                    $offset += mb_strlen($slice, '8bit');
                    $fed += mb_strlen($slice, '8bit');
                }

                if (mb_strlen($output, '8bit') >= self::PIECE) {
                    yield $output;
                    $output = '';
                }
            }
        }

        if (! $started || ! $ended) {
            throw new StoreException('The archive ended early.');
        }

        if ($output !== '') {
            yield $output;
        }
    }

    /**
     * @param  iterable<string>  $chunks
     * @return Generator<int, string> About 1 MiB per piece.
     */
    public static function deflate(iterable $chunks, int $level = 1, int $encoding = ZLIB_ENCODING_GZIP): Generator
    {
        $context = deflate_init($encoding, ['level' => $level]);
        $output = '';

        foreach ($chunks as $chunk) {
            $output .= deflate_add($context, $chunk, ZLIB_NO_FLUSH);

            if (mb_strlen($output, '8bit') >= self::PIECE) {
                yield $output;
                $output = '';
            }
        }

        $output .= deflate_add($context, '', ZLIB_FINISH);

        if ($output !== '') {
            yield $output;
        }
    }
}
