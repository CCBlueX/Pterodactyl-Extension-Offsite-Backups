<?php

declare(strict_types=1);

namespace OffsiteBackups\Transfers\Sources;

use Generator;
use OffsiteBackups\Storage\Store;
use OffsiteBackups\Transfers\Permanent;

/**
 * A backup Wings uploaded to S3 itself, read from the bucket.
 */
final readonly class StoredSource implements Source
{
    public function __construct(private Store $store, private string $path) {}

    public function open(): ?int
    {
        return $this->store->size($this->path) ?? throw new Permanent('The backup archive is missing from its S3 bucket.');
    }

    public function chunks(): Generator
    {
        yield from $this->store->read($this->path);
    }
}
