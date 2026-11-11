<?php

declare(strict_types=1);

namespace OffsiteBackups\Services;

use Generator;
use OffsiteBackups\Formats\Archive;
use OffsiteBackups\Formats\Dedup;
use OffsiteBackups\Formats\EloquentBlobIndex;
use OffsiteBackups\Models\Copy;
use OffsiteBackups\Transfers\Connector;
use OffsiteBackups\Transfers\Repositories;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Reads a copy back as the original tar.gz (or sql.gz), whatever format it is stored in.
 */
final readonly class Streamer
{
    /** Readers renew the repository's reading mark this often. */
    private const int RENEW_SECONDS = 120;

    public function __construct(
        private Connector $connector,
        private Archive $archive,
        private Dedup $dedup,
        private Repositories $repositories,
    ) {}

    /** @return Generator<int, string> */
    public function stream(Copy $copy): Generator
    {
        if ($copy->status !== 'verified' || $copy->path === null) {
            throw new ConflictHttpException('This copy is not finished.');
        }

        $destination = $copy->destination;
        [$store, $keys] = $this->connector->open($destination);

        if (! $destination->isDedup()) {
            yield from $this->archive->read($store, $copy->path, $keys);

            return;
        }

        $repository = $this->repositories->find($destination->id, $copy->server_uuid) ?? throw new ConflictHttpException('The deduplicated storage of this copy is missing.');
        $this->repositories->reading($repository);
        $renewed = time();

        foreach ($this->dedup->read($store, new EloquentBlobIndex($repository), $copy->path, $keys) as $chunk) {
            yield $chunk;

            if (time() - $renewed >= self::RENEW_SECONDS) {
                $this->repositories->reading($repository, false);
                $renewed = time();
            }
        }
    }
}
