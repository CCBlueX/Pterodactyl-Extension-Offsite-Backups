<?php

declare(strict_types=1);

namespace OffsiteBackups\Tests\Unit;

/** Builds tar streams byte by byte for the format tests. */
final class Tar
{
    private string $data = '';

    public static function header(string $name, int $size, string $type, string $link, bool $base256, int $mtime): string
    {
        $sizeField = $base256
            ? "\x80".str_repeat("\0", 3).pack('J', $size)
            : sprintf('%011o', $size)."\0";

        $header = mb_str_pad(mb_substr($name, 0, 100, '8bit'), 100, "\0")
            .sprintf('%07o', 0644)."\0"
            .sprintf('%07o', 1000)."\0"
            .sprintf('%07o', 1000)."\0"
            .$sizeField
            .sprintf('%011o', $mtime)."\0"
            .'        '
            .$type
            .mb_str_pad($link, 100, "\0")
            ."ustar\0".'00'
            .mb_str_pad('user', 32, "\0")
            .mb_str_pad('group', 32, "\0")
            .str_repeat("\0", 16)
            .str_repeat("\0", 155)
            .str_repeat("\0", 12);

        $sum = array_sum(unpack('C*', $header));

        return substr_replace($header, sprintf('%06o', $sum)."\0 ", 148, 8);
    }

    public function file(string $name, string $content, string $type = '0', string $link = '', bool $base256 = false, int $mtime = 1_700_000_000): self
    {
        $this->data .= self::header($name, mb_strlen($content, '8bit'), $type, $link, $base256, $mtime).self::padded($content);

        return $this;
    }

    /**
     * A PAX extended header for the next entry.
     *
     * @param  array<string, string|int>  $records
     */
    public function pax(array $records): self
    {
        $body = '';

        foreach ($records as $key => $value) {
            $record = " {$key}={$value}\n";
            $length = mb_strlen($record, '8bit');
            $total = $length + 1;

            while (mb_strlen((string) $total, '8bit') + $length !== $total) {
                $total = mb_strlen((string) $total, '8bit') + $length;
            }

            $body .= $total.$record;
        }

        return $this->file('PaxHeaders/entry', $body, 'x');
    }

    /** A GNU long name entry for the next entry. */
    public function longName(string $name): self
    {
        return $this->file('././@LongLink', $name."\0", 'L');
    }

    public function directory(string $name): self
    {
        $this->data .= self::header($name, 0, '5', '', false, 1_700_000_000);

        return $this;
    }

    public function bytes(string $raw): self
    {
        $this->data .= $raw;

        return $this;
    }

    /** End blocks, padded to a 10 KiB record like tar does. */
    public function end(): string
    {
        $data = $this->data.str_repeat("\0", 1024);

        return $data.str_repeat("\0", (10240 - mb_strlen($data, '8bit') % 10240) % 10240);
    }

    private static function padded(string $content): string
    {
        return $content.str_repeat("\0", (512 - mb_strlen($content, '8bit') % 512) % 512);
    }
}
