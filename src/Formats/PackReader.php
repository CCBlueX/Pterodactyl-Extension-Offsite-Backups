<?php

declare(strict_types=1);

namespace OffsiteBackups\Formats;

use OffsiteBackups\Storage\Store;
use OffsiteBackups\Storage\StoreException;

/**
 * Fetches stored blobs. Packs a copy needs much of are downloaded once into a bounded temp
 * folder; the rest is read with ranged requests.
 */
final class PackReader
{
    private ?string $folder = null;

    /** @var array<string, int> Cached packs and their size, least recently used first. */
    private array $cached = [];

    private int $cachedBytes = 0;

    /** @param array<string, int> $wanted Bytes still needed per pack. */
    public function __construct(
        private readonly Store $store,
        private readonly string $repository,
        private array $wanted,
        private readonly int $cap = 256 * 1024 * 1024,
        private readonly int $threshold = 4 * 1024 * 1024,
    ) {}

    public function blob(string $pack, int $offset, int $length): string
    {
        $wanted = $this->wanted[$pack] ?? 0;
        $this->wanted[$pack] = $wanted - $length;

        if (! isset($this->cached[$pack]) && $wanted >= $this->threshold) {
            $this->download($pack);
        }

        if (! isset($this->cached[$pack])) {
            return $this->store->readRange(Paths::pack($this->repository, $pack), $offset, $length);
        }

        $size = $this->cached[$pack];
        unset($this->cached[$pack]);
        $this->cached[$pack] = $size;

        $data = file_get_contents($this->file($pack), false, null, $offset, $length);

        if ($data === false || mb_strlen($data, '8bit') !== $length) {
            throw new StoreException("Pack {$pack} is shorter than expected.");
        }

        if ($this->wanted[$pack] <= 0) {
            $this->drop($pack);
        }

        return $data;
    }

    /** Removes the temp folder. */
    public function close(): void
    {
        foreach (array_keys($this->cached) as $pack) {
            $this->drop($pack);
        }

        if ($this->folder !== null) {
            @rmdir($this->folder);
            $this->folder = null;
        }
    }

    private function download(string $pack): void
    {
        if ($this->folder === null) {
            $folder = sys_get_temp_dir().'/offsite-backups-'.bin2hex(random_bytes(8));

            if (! @mkdir($folder, 0700)) {
                return;
            }

            $this->folder = $folder;
        }

        $file = fopen($this->file($pack), 'wb');

        if ($file === false) {
            return;
        }

        $size = 0;

        try {
            foreach ($this->store->read(Paths::pack($this->repository, $pack)) as $chunk) {
                $size += mb_strlen($chunk, '8bit');

                if ($size > $this->cap || fwrite($file, $chunk) === false) {
                    break;
                }
            }
        } finally {
            fclose($file);
        }

        if ($size > $this->cap) {
            @unlink($this->file($pack));

            return;
        }

        while ($this->cached !== [] && $this->cachedBytes + $size > $this->cap) {
            $this->drop((string) array_key_first($this->cached));
        }

        $this->cached[$pack] = $size;
        $this->cachedBytes += $size;
    }

    private function drop(string $pack): void
    {
        if (isset($this->cached[$pack])) {
            $this->cachedBytes -= $this->cached[$pack];
            unset($this->cached[$pack]);
        }

        @unlink($this->file($pack));
    }

    private function file(string $pack): string
    {
        return "{$this->folder}/{$pack}";
    }
}
