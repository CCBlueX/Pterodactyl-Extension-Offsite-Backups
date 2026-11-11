<?php

declare(strict_types=1);

namespace OffsiteBackups\Formats;

use DeflateContext;
use Generator;
use OffsiteBackups\Crypto\Keys;
use OffsiteBackups\Crypto\StreamCipher;
use OffsiteBackups\Storage\Store;
use OffsiteBackups\Storage\StoreException;

/**
 * The recipe of one deduplicated copy: literal bytes and blob references in stream order.
 * Layout before compression: "OFSM", version, kind (0 raw, 1 tar), then records
 * 0x01 literal (u32 length, bytes), 0x02 blob (32 byte id, u32 raw length), 0x03 end (u32 length, JSON stats).
 * The record stream is raw-deflated, and encrypted with the stream cipher when the destination is.
 */
final class Manifest
{
    public const int LITERAL = 1;

    public const int BLOB = 2;

    private const int END = 3;

    private const string MAGIC = "OFSM\x01";

    private const int LITERAL_LIMIT = 1024 * 1024;

    /** @var resource */
    private $buffer;

    private DeflateContext $deflate;

    private string $literal = '';

    public function __construct(bool $tar)
    {
        $buffer = fopen('php://temp/maxmemory:'.(4 * 1024 * 1024), 'w+b');

        if ($buffer === false) {
            throw new StoreException('Could not open a temporary buffer.');
        }

        $this->buffer = $buffer;
        $this->deflate = deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]);
        $this->write(self::MAGIC.chr($tar ? 1 : 0));
    }

    /**
     * Reads a stored manifest record by record: [LITERAL, bytes, 0] or [BLOB, id, raw length].
     *
     * @return Generator<int, array{0: int, 1: string, 2: int}, mixed, array<string, mixed>> Returns the stats.
     */
    public static function read(Store $store, string $path, ?Keys $keys): Generator
    {
        $chunks = $store->read($path);
        $source = Gzip::inflate($keys !== null ? StreamCipher::decrypt($chunks, $keys) : $chunks, ZLIB_ENCODING_RAW);
        $buffer = '';
        $offset = 0;

        $take = static function (int $length) use ($source, &$buffer, &$offset): string {
            while (mb_strlen($buffer, '8bit') - $offset < $length) {
                if (! $source->valid()) {
                    throw new StoreException('The copy\'s manifest is damaged.');
                }

                $buffer = mb_substr($buffer, $offset, null, '8bit').$source->current();
                $offset = 0;
                $source->next();
            }

            $bytes = mb_substr($buffer, $offset, $length, '8bit');
            $offset += $length;

            return $bytes;
        };

        if ($take(5) !== self::MAGIC) {
            throw new StoreException('The copy\'s manifest is damaged or from an unknown version.');
        }

        $kind = ord($take(1));

        while (true) {
            $type = ord($take(1));

            if ($type === self::LITERAL) {
                yield [self::LITERAL, $take(unpack('N', $take(4))[1]), 0];
            } elseif ($type === self::BLOB) {
                $id = $take(32);

                yield [self::BLOB, $id, unpack('N', $take(4))[1]];
            } elseif ($type === self::END) {
                $stats = json_decode($take(unpack('N', $take(4))[1]), true);

                return ['tar' => $kind === 1] + (is_array($stats) ? $stats : []);
            } else {
                throw new StoreException('The copy\'s manifest is damaged.');
            }
        }
    }

    public function literal(string $bytes): void
    {
        $this->literal .= $bytes;

        if (mb_strlen($this->literal, '8bit') >= self::LITERAL_LIMIT) {
            $this->flushLiteral();
        }
    }

    public function blob(string $id, int $length): void
    {
        $this->flushLiteral();
        $this->write(chr(self::BLOB).$id.pack('N', $length));
    }

    /**
     * Ends the manifest and returns its stored form.
     *
     * @param  array<string, mixed>  $stats
     * @return Generator<int, string>
     */
    public function finish(array $stats, ?Keys $keys): Generator
    {
        $this->flushLiteral();
        $json = json_encode($stats, JSON_THROW_ON_ERROR);
        $this->write(chr(self::END).pack('N', mb_strlen($json, '8bit')).$json);
        fwrite($this->buffer, deflate_add($this->deflate, '', ZLIB_FINISH));
        rewind($this->buffer);

        $chunks = (function (): Generator {
            try {
                while (! feof($this->buffer)) {
                    $data = fread($this->buffer, 1024 * 1024);

                    if ($data !== false && $data !== '') {
                        yield $data;
                    }
                }
            } finally {
                fclose($this->buffer);
            }
        })();

        return $keys !== null ? StreamCipher::encrypt($chunks, $keys) : $chunks;
    }

    private function flushLiteral(): void
    {
        if ($this->literal === '') {
            return;
        }

        $this->write(chr(self::LITERAL).pack('N', mb_strlen($this->literal, '8bit')).$this->literal);
        $this->literal = '';
    }

    private function write(string $data): void
    {
        $output = deflate_add($this->deflate, $data, ZLIB_NO_FLUSH);

        if ($output !== '') {
            fwrite($this->buffer, $output);
        }
    }
}
