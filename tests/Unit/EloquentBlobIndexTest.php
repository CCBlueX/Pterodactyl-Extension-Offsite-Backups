<?php

declare(strict_types=1);

namespace OffsiteBackups\Tests\Unit;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use OffsiteBackups\Formats\Dedup;
use OffsiteBackups\Formats\EloquentBlobIndex;
use OffsiteBackups\Formats\Gzip;
use OffsiteBackups\Formats\Paths;
use OffsiteBackups\Models\Repository;
use OffsiteBackups\Storage\MemoryStore;
use PHPUnit\Framework\TestCase;

final class EloquentBlobIndexTest extends TestCase
{
    private Repository $repository;

    protected function setUp(): void
    {
        $capsule = new Manager;
        $capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $schema = $capsule->schema();
        $schema->create('ext_offsite_backups_repositories', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('destination_id');
            $table->char('server_uuid', 36);
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('dirty')->default(false);
            $table->timestamp('reading_until')->nullable();
            $table->timestamp('pruned_at')->nullable();
            $table->timestamps();
        });
        $schema->create('ext_offsite_backups_packs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('repository_id');
            $table->char('name', 64);
            $table->unsignedBigInteger('size');
            $table->timestamp('created_at')->nullable();
            $table->unique(['repository_id', 'name']);
        });
        $schema->create('ext_offsite_backups_blobs', function (Blueprint $table): void {
            $table->unsignedInteger('repository_id');
            $table->binary('hash', 32, true);
            $table->unsignedBigInteger('pack_id');
            $table->unsignedBigInteger('offset');
            $table->unsignedInteger('length');
            $table->primary(['repository_id', 'hash']);
        });

        $this->repository = Repository::query()->create(['destination_id' => 1, 'server_uuid' => 'server']);
        Repository::query()->create(['destination_id' => 2, 'server_uuid' => 'server']);
    }

    public function test_packs_and_blobs_are_recorded(): void
    {
        $index = new EloquentBlobIndex($this->repository);
        $ids = array_map(static fn (): string => random_bytes(32), range(1, 2500));
        $blobs = array_map(static fn (string $id, int $i): array => [$id, $i * 100, 100], $ids, array_keys($ids));

        $this->assertFalse($index->has($ids[0]));

        $index->addPack(str_repeat('a', 64), 250_000, $blobs);

        $this->assertTrue($index->has($ids[2499]));
        $this->assertTrue((new EloquentBlobIndex($this->repository))->has($ids[1234]));
        $this->assertFalse((new EloquentBlobIndex(Repository::query()->find(2)))->has($ids[0]));
        $this->assertSame(['pack' => str_repeat('a', 64), 'offset' => 1000, 'length' => 100], $index->locate($ids[10]));
        $this->assertSame(250_000, $index->size());
        $this->assertSame(250_000, Repository::query()->find($this->repository->id)->size);
        $this->assertCount(2500, $index->blobsOf(str_repeat('a', 64)));

        $index->addPack(str_repeat('b', 64), 300, [[$ids[0], 0, 100], [$moved = random_bytes(32), 100, 200]]);
        $this->assertSame(str_repeat('b', 64), $index->locate($ids[0])['pack']);
        $this->assertCount(2499, $index->blobsOf(str_repeat('a', 64)));

        $index->removePack(str_repeat('a', 64));

        $this->assertFalse($index->has($ids[5]));
        $this->assertTrue($index->has($ids[0]));
        $this->assertTrue($index->has($moved));
        $this->assertNull($index->locate($ids[5]));
        $this->assertSame([['name' => str_repeat('b', 64), 'size' => 300]], iterator_to_array($index->packs(), false));
        $this->assertSame(300, Repository::query()->find($this->repository->id)->size);
    }

    public function test_dedup_runs_on_the_database_index(): void
    {
        $store = new MemoryStore;
        $manifest = Paths::manifest('server', 'copy');
        $tar = (new Tar)->file('a.bin', random_bytes(3 * 1024 * 1024))->file('b.txt', str_repeat('text ', 1000))->end();

        (new Dedup)->write($store, new EloquentBlobIndex($this->repository), $manifest, [gzencode($tar, 1)], null, true, static function (): void {});
        $again = (new Dedup)->write($store, new EloquentBlobIndex($this->repository), Paths::manifest('server', 'again'), [gzencode($tar, 1)], null, true, static function (): void {});

        $this->assertSame(0, $again->details['new_blobs']);
        $this->assertSame($tar, implode('', iterator_to_array(Gzip::inflate((new Dedup)->read($store, new EloquentBlobIndex($this->repository), $manifest, null)), false)));
    }
}
