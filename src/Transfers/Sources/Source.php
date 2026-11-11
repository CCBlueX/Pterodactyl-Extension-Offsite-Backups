<?php

declare(strict_types=1);

namespace OffsiteBackups\Transfers\Sources;

use Generator;

/**
 * The bytes a copy is made from: a gzip stream (tar.gz backup or sql.gz dump).
 */
interface Source
{
    /** Connects to the source; returns its size when known. */
    public function open(): ?int;

    /** @return Generator<int, string> */
    public function chunks(): Generator;
}
