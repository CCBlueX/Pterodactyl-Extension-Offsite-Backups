<?php

declare(strict_types=1);

namespace OffsiteBackups\Storage;

use RuntimeException;

/** A destination failure with a message an admin can act on. */
class StoreException extends RuntimeException
{
    public static function missing(string $path): self
    {
        return new self("{$path} does not exist on the destination.");
    }
}
