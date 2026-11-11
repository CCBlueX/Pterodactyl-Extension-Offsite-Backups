<?php

declare(strict_types=1);

namespace OffsiteBackups\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OffsiteBackups\Crypto\Vault;
use OffsiteBackups\Http\Presenters\DestinationPresenter;
use OffsiteBackups\Http\Requests\DestinationInput;
use OffsiteBackups\Models\Destination;
use OffsiteBackups\Models\Rule;
use OffsiteBackups\Models\ServerSetting;
use OffsiteBackups\Models\Stored;
use OffsiteBackups\Services\Copies;
use OffsiteBackups\Settings;
use OffsiteBackups\Storage\Store;
use OffsiteBackups\Storage\StoreException;
use OffsiteBackups\Storage\Stores;
use Throwable;

final class DestinationController
{
    public function __construct(private readonly Copies $copies, private readonly Settings $settings) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => DestinationPresenter::query()->get()->map(DestinationPresenter::present(...))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        $attributes = DestinationInput::validate($request);
        $this->assertUniqueName($attributes['name']);

        $destination = Destination::query()->create([
            ...$attributes,
            'is_default' => ! Destination::query()->exists(),
            'status' => 'ok',
        ]);

        return new JsonResponse(DestinationPresenter::present($destination), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $destination = Destination::query()->findOrFail($id);
        $attributes = DestinationInput::validate($request, $destination);
        $this->assertUniqueName($attributes['name'], $destination->id);

        if ($destination->copies()->exists() && ($problem = DestinationInput::lockedChange($destination, $attributes)) !== null) {
            throw ValidationException::withMessages(['format' => $problem]);
        }

        if ($attributes['kind'] !== 's3' && $this->usedAsBackupStorage($destination->id)) {
            throw ValidationException::withMessages(['kind' => 'Wings stores backups in this destination, so it has to stay an S3 bucket.']);
        }

        if ($request->has('paused')) {
            $attributes['status'] = $request->boolean('paused') ? 'paused' : 'ok';
        }

        $destination->update($attributes);

        return new JsonResponse(DestinationPresenter::present($destination->refresh()));
    }

    public function destroy(Request $request, int $id): Response
    {
        $destination = Destination::query()->findOrFail($id);

        abort_if($this->usedAsBackupStorage($destination->id), 409, 'Wings stores backups in this destination. Pick another backup storage first.');
        abort_if(Stored::query()->where('destination_id', $destination->id)->exists(), 409, 'Backups that Wings uploaded are still kept in this bucket. Delete those backups first.');

        if ($destination->copies()->exists()) {
            abort_unless($request->boolean('with_copies'), 409, 'This destination still holds copies.');
            abort_if($destination->copies()->where('status', 'running')->exists(), 409, 'A copy to this destination is running. Try again when it is done.');

            set_time_limit(0);
            foreach ($destination->copies()->lazyById(100) as $copy) {
                $this->copies->delete($copy);
            }
            abort_if($destination->copies()->exists(), 409, 'Some copies could not be deleted.');
        }

        DB::transaction(static function () use ($destination): void {
            Rule::query()->get()->each(static function (Rule $rule) use ($destination): void {
                $ids = array_map(intval(...), $rule->destination_ids ?? []);
                if (in_array($destination->id, $ids, true)) {
                    $remaining = array_values(array_diff($ids, [$destination->id]));
                    $rule->update(['destination_ids' => $remaining, 'enabled' => $rule->enabled && $remaining !== []]);
                }
            });
            ServerSetting::query()->where('storage_destination_id', $destination->id)->update(['storage' => 'default', 'storage_destination_id' => null]);
            $destination->delete();

            if ($destination->is_default) {
                Destination::query()->orderBy('id')->first()?->update(['is_default' => true]);
            }
        });

        return response()->noContent();
    }

    /** Tests the destination form without saving it; with an "id" the stored secrets fill empty fields. */
    public function test(Request $request): JsonResponse
    {
        $existing = $request->filled('id') ? Destination::query()->findOrFail($request->integer('id')) : null;
        $attributes = DestinationInput::validate($request, $existing);

        return new JsonResponse($this->probe(static fn (): Store => Stores::fromConfig($attributes['kind'], $attributes['config']), $attributes['encrypted'] ? $attributes['passphrase'] : null));
    }

    public function testExisting(int $id): JsonResponse
    {
        $destination = Destination::query()->findOrFail($id);
        $result = $this->probe(static fn (): Store => Stores::for($destination), $destination->encrypted ? $destination->passphrase : null);

        $destination->update([
            'status' => $destination->status === 'paused' ? 'paused' : ($result['ok'] ? 'ok' : 'error'),
            'status_message' => $result['ok'] ? null : $result['message'],
            'checked_at' => now(),
        ]);

        return new JsonResponse($result);
    }

    public function makeDefault(int $id): JsonResponse
    {
        $destination = Destination::query()->findOrFail($id);

        DB::transaction(static function () use ($destination): void {
            Destination::query()->whereKeyNot($destination->id)->update(['is_default' => false]);
            $destination->update(['is_default' => true]);
        });

        return new JsonResponse(DestinationPresenter::present($destination->refresh()));
    }

    /**
     * @param  callable(): Store  $store
     * @return array{ok: bool, message: string}
     */
    private function probe(callable $store, ?string $passphrase): array
    {
        set_time_limit(120);

        try {
            $connected = $store();
            $connected->probe();
            Vault::open($connected, $passphrase, create: false);

            return ['ok' => true, 'message' => 'Connected. Writing, reading and deleting a test file worked.'];
        } catch (StoreException $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        } catch (Throwable $exception) {
            report($exception);

            return ['ok' => false, 'message' => 'The destination could not be used: '.$exception->getMessage()];
        }
    }

    private function usedAsBackupStorage(int $id): bool
    {
        return $this->settings->storageDestinationId() === $id
            || ServerSetting::query()->where('storage', 's3')->where('storage_destination_id', $id)->exists();
    }

    private function assertUniqueName(string $name, ?int $except = null): void
    {
        if (Destination::query()->where('name', $name)->when($except !== null, static fn ($query) => $query->whereKeyNot($except))->exists()) {
            throw ValidationException::withMessages(['name' => 'Another destination already has this name.']);
        }
    }
}
