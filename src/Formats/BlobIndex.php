<?php

declare(strict_types=1);

namespace OffsiteBackups\Formats;

/** Where each blob of a deduplicated repository lives. */
interface BlobIndex
{
    public function has(string $id): bool;

    /** @return array{pack: string, offset: int, length: int}|null */
    public function locate(string $id): ?array;

    /**
     * Records an uploaded pack. A blob that is already known moves to this pack.
     *
     * @param  list<array{0: string, 1: int, 2: int}>  $blobs  id, offset, length
     */
    public function addPack(string $name, int $size, array $blobs): void;

    /** @return iterable<array{name: string, size: int}> */
    public function packs(): iterable;

    /** @return list<array{0: string, 1: int, 2: int}> id, offset, length */
    public function blobsOf(string $pack): array;

    /** Forgets a pack and the blobs that still point to it. */
    public function removePack(string $name): void;

    /** Bytes in packs. */
    public function size(): int;
}
