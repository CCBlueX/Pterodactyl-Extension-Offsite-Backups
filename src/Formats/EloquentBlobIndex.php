<?php

declare(strict_types=1);

namespace OffsiteBackups\Formats;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use OffsiteBackups\Models\Repository;

/** The blob index of one repository, in the packs and blobs tables. */
final class EloquentBlobIndex implements BlobIndex
{
    private const string PACKS = 'ext_offsite_backups_packs';

    private const string BLOBS = 'ext_offsite_backups_blobs';

    private const int BATCH = 1000;

    /** @var array<string, true>|null */
    private ?array $ids = null;

    /** @var array<int, string>|null */
    private ?array $names = null;

    public function __construct(private readonly Repository $repository) {}

    public function has(string $id): bool
    {
        $this->ids ??= $this->loadIds();

        return isset($this->ids[$id]);
    }

    public function locate(string $id): ?array
    {
        $row = $this->blobs()->where('hash', $id)->first(['pack_id', 'offset', 'length']);

        if ($row === null) {
            return null;
        }

        $pack = $this->packNames()[(int) $row->pack_id] ?? null;

        return $pack === null ? null : ['pack' => $pack, 'offset' => (int) $row->offset, 'length' => (int) $row->length];
    }

    public function addPack(string $name, int $size, array $blobs): void
    {
        $repository = $this->repository->getKey();

        $packId = $this->connection()->transaction(function () use ($name, $size, $blobs, $repository): int {
            $packId = $this->packsTable()->where('name', $name)->value('id');
            $packId = $packId !== null ? (int) $packId : (int) $this->connection()->table(self::PACKS)->insertGetId([
                'repository_id' => $repository,
                'name' => $name,
                'size' => $size,
                'created_at' => CarbonImmutable::now(),
            ]);

            foreach (array_chunk($blobs, self::BATCH) as $batch) {
                $this->connection()->table(self::BLOBS)->upsert(
                    array_map(static fn (array $blob): array => [
                        'repository_id' => $repository,
                        'hash' => $blob[0],
                        'pack_id' => $packId,
                        'offset' => $blob[1],
                        'length' => $blob[2],
                    ], $batch),
                    ['repository_id', 'hash'],
                    ['pack_id', 'offset', 'length'],
                );
            }

            $this->syncSize();

            return $packId;
        });

        if ($this->names !== null) {
            $this->names[$packId] = $name;
        }

        if ($this->ids !== null) {
            foreach ($blobs as [$id]) {
                $this->ids[$id] = true;
            }
        }
    }

    public function packs(): iterable
    {
        return $this->packsTable()->orderBy('id')->get(['name', 'size'])
            ->map(static fn (object $pack): array => ['name' => (string) $pack->name, 'size' => (int) $pack->size])
            ->all();
    }

    public function blobsOf(string $pack): array
    {
        $packId = $this->packsTable()->where('name', $pack)->value('id');

        if ($packId === null) {
            return [];
        }

        return $this->blobs()->where('pack_id', $packId)->orderBy('offset')->get(['hash', 'offset', 'length'])
            ->map(static fn (object $blob): array => [(string) $blob->hash, (int) $blob->offset, (int) $blob->length])
            ->all();
    }

    public function removePack(string $name): void
    {
        $packId = $this->packsTable()->where('name', $name)->value('id');

        if ($packId === null) {
            return;
        }

        $removed = $this->connection()->transaction(function () use ($packId): array {
            $hashes = $this->blobs()->where('pack_id', $packId)->pluck('hash')->all();
            $this->blobs()->where('pack_id', $packId)->delete();
            $this->packsTable()->where('id', $packId)->delete();
            $this->syncSize();

            return $hashes;
        });

        unset($this->names[(int) $packId]);

        if ($this->ids !== null) {
            foreach ($removed as $hash) {
                unset($this->ids[(string) $hash]);
            }
        }
    }

    public function size(): int
    {
        return (int) $this->packsTable()->sum('size');
    }

    /** @return array<string, true> */
    private function loadIds(): array
    {
        $ids = [];
        $last = null;

        // Keyset paging keeps the result sets small on big repositories.
        do {
            $query = $this->blobs()->orderBy('hash')->limit(10000);

            if ($last !== null) {
                $query->where('hash', '>', $last);
            }

            $hashes = $query->pluck('hash')->all();

            foreach ($hashes as $hash) {
                $ids[(string) $hash] = true;
                $last = $hash;
            }
        } while (count($hashes) === 10000);

        return $ids;
    }

    /** @return array<int, string> */
    private function packNames(): array
    {
        return $this->names ??= $this->packsTable()->pluck('name', 'id')->map(static fn (mixed $name): string => (string) $name)->all();
    }

    private function syncSize(): void
    {
        $size = (int) $this->packsTable()->sum('size');
        $this->connection()->table($this->repository->getTable())->where('id', $this->repository->getKey())->update(['size' => $size]);
        $this->repository->setAttribute('size', $size);
    }

    private function packsTable(): Builder
    {
        return $this->connection()->table(self::PACKS)->where('repository_id', $this->repository->getKey());
    }

    private function blobs(): Builder
    {
        return $this->connection()->table(self::BLOBS)->where('repository_id', $this->repository->getKey());
    }

    private function connection(): ConnectionInterface
    {
        return $this->repository->getConnection();
    }
}
