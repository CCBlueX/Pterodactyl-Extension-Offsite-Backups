<?php

declare(strict_types=1);

namespace OffsiteBackups\Formats;

use OffsiteBackups\Models\Destination;

final readonly class Formats
{
    /** The writer for copies on this destination. */
    public static function for(Destination $destination): Archive|Dedup
    {
        return $destination->isDedup() ? new Dedup : new Archive;
    }
}
