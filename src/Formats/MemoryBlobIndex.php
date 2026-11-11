<?php

declare(strict_types=1);

namespace OffsiteBackups\Formats;

/** A blob index in memory, for tests. */
final class MemoryBlobIndex implements BlobIndex
{
    /** @var array<string, int> */
    private array $packs = [];

    /** @var array<string, array{pack: string, offset: int, length: int}> */
    private array $blobs = [];

    public function has(string $id): bool
    {
        return isset($this->blobs[$id]);
    }

    public function locate(string $id): ?array
    {
        return $this->blobs[$id] ?? null;
    }

    public function addPack(string $name, int $size, array $blobs): void
    {
        $this->packs[$name] = $size;

        foreach ($blobs as [$id, $offset, $length]) {
            $this->blobs[$id] = ['pack' => $name, 'offset' => $offset, 'length' => $length];
        }
    }

    public function packs(): iterable
    {
        foreach ($this->packs as $name => $size) {
            yield ['name' => (string) $name, 'size' => $size];
        }
    }

    public function blobsOf(string $pack): array
    {
        $blobs = [];

        foreach ($this->blobs as $id => $blob) {
            if ($blob['pack'] === $pack) {
                $blobs[] = [(string) $id, $blob['offset'], $blob['length']];
            }
        }

        usort($blobs, static fn (array $a, array $b): int => $a[1] <=> $b[1]);

        return $blobs;
    }

    public function removePack(string $name): void
    {
        unset($this->packs[$name]);
        $this->blobs = array_filter($this->blobs, static fn (array $blob): bool => $blob['pack'] !== $name);
    }

    public function size(): int
    {
        return array_sum($this->packs);
    }
}
