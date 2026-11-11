<?php

declare(strict_types=1);

namespace OffsiteBackups\Formats;

use OffsiteBackups\Storage\Store;

/** Collects stored blobs into a pack and uploads it once it is big enough. */
final class PackWriter
{
    /** Bytes of the packs uploaded so far. */
    public int $written = 0;

    private string $pack = '';

    /** @var list<array{0: string, 1: int, 2: int}> */
    private array $blobs = [];

    /** @var array<string, true> */
    private array $pending = [];

    public function __construct(
        private readonly Store $store,
        private readonly BlobIndex $index,
        private readonly string $repository,
        private readonly int $limit,
    ) {}

    public function add(string $id, string $stored): void
    {
        $this->blobs[] = [$id, mb_strlen($this->pack, '8bit'), mb_strlen($stored, '8bit')];
        $this->pack .= $stored;
        $this->pending[$id] = true;
    }

    /** The blob waits in the pack that is not uploaded yet. */
    public function holds(string $id): bool
    {
        return isset($this->pending[$id]);
    }

    public function full(): bool
    {
        return mb_strlen($this->pack, '8bit') >= $this->limit;
    }

    /** @return bool Whether a pack was uploaded. */
    public function flush(): bool
    {
        if ($this->pack === '') {
            return false;
        }

        $name = hash('sha256', $this->pack);
        $size = mb_strlen($this->pack, '8bit');
        $this->store->put(Paths::pack($this->repository, $name), $this->pack);
        $this->index->addPack($name, $size, $this->blobs);
        $this->written += $size;
        $this->pack = '';
        $this->blobs = [];
        $this->pending = [];

        return true;
    }
}
