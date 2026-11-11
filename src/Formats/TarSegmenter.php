<?php

declare(strict_types=1);

namespace OffsiteBackups\Formats;

use Generator;

/**
 * Splits a tar stream into file contents and everything else, without losing a byte.
 * Understands USTAR, PAX (size records) and GNU (base-256 sizes, long names); anything it
 * cannot parse is passed on as literal bytes.
 */
final class TarSegmenter
{
    /** Bytes stored as they are: headers, metadata entries, padding, end blocks, trailing data. */
    public const int LITERAL = 0;

    /** Contents of a regular file. */
    public const int DATA = 1;

    /** A regular file ended (also sent for empty files). */
    public const int END = 2;

    private const int BLOCK = 512;

    private const int HEADER = 0;

    private const int BODY = 1;

    private const int PADDING = 2;

    private const int TRAILER = 3;

    private const int PAX_LIMIT = 1024 * 1024;

    private int $state = self::HEADER;

    private string $pending = '';

    private int $remaining = 0;

    private int $padding = 0;

    private bool $regular = false;

    private bool $collecting = false;

    private string $pax = '';

    private ?int $nextSize = null;

    /** @return Generator<int, array{0: int, 1: string}> */
    public function feed(string $data): Generator
    {
        if ($this->pending !== '') {
            $data = $this->pending.$data;
            $this->pending = '';
        }

        $length = mb_strlen($data, '8bit');
        $offset = 0;

        while ($offset < $length) {
            if ($this->state === self::TRAILER) {
                yield [self::LITERAL, $offset === 0 ? $data : mb_substr($data, $offset, null, '8bit')];

                return;
            }

            if ($this->state === self::HEADER) {
                if ($length - $offset < self::BLOCK) {
                    $this->pending = mb_substr($data, $offset, null, '8bit');

                    return;
                }

                $header = mb_substr($data, $offset, self::BLOCK, '8bit');
                $offset += self::BLOCK;

                yield [self::LITERAL, $header];

                $this->header($header);

                if ($this->state === self::BODY && $this->remaining === 0) {
                    yield from $this->endBody();
                }

                continue;
            }

            if ($this->state === self::BODY) {
                $take = min($this->remaining, $length - $offset);
                $piece = mb_substr($data, $offset, $take, '8bit');
                $offset += $take;
                $this->remaining -= $take;

                if ($this->regular) {
                    yield [self::DATA, $piece];
                } else {
                    if ($this->collecting && mb_strlen($this->pax, '8bit') < self::PAX_LIMIT) {
                        $this->pax .= $piece;
                    }

                    yield [self::LITERAL, $piece];
                }

                if ($this->remaining === 0) {
                    yield from $this->endBody();
                }

                continue;
            }

            $take = min($this->padding, $length - $offset);
            yield [self::LITERAL, mb_substr($data, $offset, $take, '8bit')];
            $offset += $take;
            $this->padding -= $take;

            if ($this->padding === 0) {
                $this->state = self::HEADER;
            }
        }
    }

    /**
     * Passes on what is left at the end of the stream.
     *
     * @return Generator<int, array{0: int, 1: string}>
     */
    public function finish(): Generator
    {
        if ($this->pending !== '') {
            yield [self::LITERAL, $this->pending];
            $this->pending = '';
        }

        if ($this->state === self::BODY && $this->regular) {
            $this->state = self::TRAILER;

            yield [self::END, ''];
        }
    }

    /** @return Generator<int, array{0: int, 1: string}> */
    private function endBody(): Generator
    {
        if ($this->regular) {
            yield [self::END, ''];
        }

        if ($this->collecting) {
            if (preg_match('/(?:^|\n)\d+ size=(\d+)\n/', $this->pax, $match) === 1) {
                $this->nextSize = (int) $match[1];
            }

            $this->collecting = false;
            $this->pax = '';
        }

        $this->state = $this->padding > 0 ? self::PADDING : self::HEADER;
    }

    private function header(string $header): void
    {
        if (strspn($header, "\0") === self::BLOCK) {
            return;
        }

        $size = $this->number(mb_substr($header, 124, 12, '8bit'));
        $checksum = $this->number(mb_substr($header, 148, 8, '8bit'));

        if ($size === null || $checksum === null || $checksum !== $this->checksum($header)) {
            $this->state = self::TRAILER;

            return;
        }

        $type = $header[156];

        if (! in_array($type, ['x', 'g', 'L', 'K'], true) && $this->nextSize !== null) {
            $size = $this->nextSize;
            $this->nextSize = null;
        }

        if (in_array($type, ['1', '2', '3', '4', '5', '6'], true)) {
            $size = 0;
        }

        $this->regular = in_array($type, ['0', "\0", '7'], true);
        $this->collecting = $type === 'x';
        $this->remaining = $size;
        $this->padding = (self::BLOCK - $size % self::BLOCK) % self::BLOCK;
        $this->state = self::BODY;
    }

    private function checksum(string $header): int
    {
        return array_sum(unpack('C*', mb_substr($header, 0, 148, '8bit'))) + 8 * 32 + array_sum(unpack('C*', mb_substr($header, 156, null, '8bit')));
    }

    /** Octal, or base-256 when the high bit of the first byte is set. */
    private function number(string $field): ?int
    {
        if ((ord($field[0]) & 0x80) !== 0) {
            $value = ord($field[0]) & 0x7F;

            for ($i = 1, $length = mb_strlen($field, '8bit'); $i < $length; $i++) {
                if ($value > (PHP_INT_MAX >> 8)) {
                    return null;
                }

                $value = ($value << 8) | ord($field[$i]);
            }

            return $value;
        }

        if (preg_match('/^[ \0]*([0-7]*)[ \0]*$/', $field, $match) !== 1) {
            return null;
        }

        return $match[1] === '' ? 0 : (int) octdec($match[1]);
    }
}
