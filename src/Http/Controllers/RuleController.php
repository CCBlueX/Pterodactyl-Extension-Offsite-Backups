<?php

declare(strict_types=1);

namespace OffsiteBackups\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OffsiteBackups\Http\Presenters\RulePresenter;
use OffsiteBackups\Http\Requests\RuleInput;
use OffsiteBackups\Models\Destination;
use OffsiteBackups\Models\Rule;
use OffsiteBackups\Rules\Matcher;
use OffsiteBackups\Services\Allowance;
use OffsiteBackups\Services\Copies;
use Pterodactyl\Models\Server;
use Throwable;

final class RuleController
{
    public function __construct(private readonly Copies $copies) {}

    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => RulePresenter::many(Rule::query()->orderBy('sort')->orderBy('id')->get())]);
    }

    public function store(Request $request): JsonResponse
    {
        $attributes = RuleInput::validate($request);
        if (! $request->has('sort')) {
            $attributes['sort'] = min(65535, (int) Rule::query()->max('sort') + 1);
        }

        return new JsonResponse(RulePresenter::one(Rule::query()->create($attributes)), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $rule = Rule::query()->findOrFail($id);

        if (array_diff(array_keys($request->all()), ['enabled', 'sort']) === []) {
            $rule->fill($request->validate([
                'enabled' => ['sometimes', 'boolean'],
                'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            ]));
            if ($rule->enabled && $rule->trigger === 'schedule' && $rule->cron !== null && $rule->isDirty('enabled')) {
                $rule->next_run_at = RuleInput::nextRun($rule->cron, CarbonImmutable::now(), (string) config('app.timezone', 'UTC'));
            }
            $rule->save();

            return new JsonResponse(RulePresenter::one($rule->refresh()));
        }

        $attributes = RuleInput::validate($request);
        if (! $request->has('sort')) {
            unset($attributes['sort']);
        }

        $rule->update($attributes);

        return new JsonResponse(RulePresenter::one($rule->refresh()));
    }

    public function destroy(int $id): Response
    {
        Rule::query()->findOrFail($id)->delete();

        return response()->noContent();
    }

    /** Backup rules copy the latest backup of every matching server; schedule rules are planned on the next tick. */
    public function run(int $id): JsonResponse
    {
        $rule = Rule::query()->findOrFail($id);
        abort_unless($rule->enabled, 409, 'Turn the rule on before running it.');

        if ($rule->trigger === 'schedule') {
            $rule->update(['next_run_at' => now()]);

            return new JsonResponse(['queued' => Matcher::servers($rule)->count()]);
        }

        set_time_limit(0);
        $destinations = Destination::query()->whereKey($rule->destination_ids ?? [])->get()->keyBy('id');
        $ordered = collect($rule->destination_ids ?? [])->map(static fn (mixed $id): ?Destination => $destinations->get((int) $id))->filter()->values();
        $queued = 0;

        Matcher::servers($rule)->chunkById(100, function (Collection $servers) use ($ordered, &$queued): void {
            foreach ($servers as $server) {
                /** @var Server $server */
                if (! Allowance::for($server)['enabled']) {
                    continue;
                }

                foreach ($ordered as $destination) {
                    try {
                        $queued += $this->copies->queueLatest($server, $destination) !== null ? 1 : 0;
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }
            }
        }, 'servers.id', 'id');

        return new JsonResponse(['queued' => $queued]);
    }
}
