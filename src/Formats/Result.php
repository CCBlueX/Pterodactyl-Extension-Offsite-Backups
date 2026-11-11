<?php

declare(strict_types=1);

namespace OffsiteBackups\Formats;

final readonly class Result
{
    /**
     * @param  int  $sourceBytes  Bytes read from the source.
     * @param  string  $sha1  Hex sha1 of the source bytes.
     * @param  int  $storedBytes  New bytes written to the destination.
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public int $sourceBytes,
        public string $sha1,
        public int $storedBytes,
        public array $details = [],
    ) {}
}
