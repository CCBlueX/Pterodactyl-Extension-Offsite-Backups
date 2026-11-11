<?php

declare(strict_types=1);

namespace OffsiteBackups\Transfers\Sources;

use Generator;
use OffsiteBackups\Formats\Gzip;
use OffsiteBackups\Transfers\DatabaseDumper;
use Pterodactyl\Models\Database;

/**
 * A gzipped SQL dump of one server database.
 */
final readonly class DumpSource implements Source
{
    public function __construct(private DatabaseDumper $dumper, private Database $database) {}

    public function open(): ?int
    {
        return null;
    }

    public function chunks(): Generator
    {
        yield from Gzip::deflate($this->dumper->dump($this->database), 6);
    }
}
