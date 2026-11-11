<?php

declare(strict_types=1);

namespace OffsiteBackups\Http\Requests;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OffsiteBackups\Models\Destination;
use OffsiteBackups\Models\Rule;

final readonly class RuleInput
{
    private const array NUMBERS = ['keep_newest', 'keep_daily', 'keep_weekly', 'keep_monthly', 'max_age_days', 'keep_local'];

    /** @return array<string, mixed> Rule attributes, with next_run_at for schedule rules. */
    public static function validate(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'enabled' => ['required', 'boolean'],
            'sort' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'target_type' => ['required', 'string', 'in:'.implode(',', Rule::TARGETS)],
            'target_ids' => ['present', 'array', 'max:500'],
            'target_ids.*' => ['integer', 'min:1'],
            'excluded_server_ids' => ['sometimes', 'array', 'max:500'],
            'excluded_server_ids.*' => ['integer', 'min:1'],
            'trigger' => ['required', 'string', 'in:'.implode(',', Rule::TRIGGERS)],
            'cron' => ['nullable', 'string', 'max:64'],
            'skip_idle' => ['sometimes', 'boolean'],
            'when_full' => ['sometimes', 'string', 'in:skip,rotate'],
            'destination_ids' => ['required', 'array', 'min:1', 'max:10'],
            'destination_ids.*' => ['integer', 'min:1'],
            'retention' => ['required', 'string', 'in:newest,gfs'],
            ...array_fill_keys(self::NUMBERS, ['nullable', 'integer', 'min:1', 'max:1000']),
            'include_databases' => ['sometimes', 'boolean'],
        ]);

        $attributes = self::normalise($data);
        $problems = self::problems($attributes);

        $known = Destination::query()->whereKey($attributes['destination_ids'])->count();
        if ($known !== count($attributes['destination_ids'])) {
            $problems['destination_ids'] = 'One of the destinations does not exist anymore.';
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        $attributes['next_run_at'] = $attributes['trigger'] === 'schedule'
            ? self::nextRun((string) $attributes['cron'], CarbonImmutable::now(), (string) config('app.timezone', 'UTC'))
            : null;

        return $attributes;
    }

    /**
     * Casts validated input and clears the fields the chosen trigger and retention do not use.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalise(array $data): array
    {
        $ids = static fn (mixed $values): array => array_values(array_unique(array_map(intval(...), array_filter((array) $values, is_numeric(...)))));
        $number = static fn (string $key): ?int => is_numeric($data[$key] ?? null) ? (int) $data[$key] : null;
        $schedule = ($data['trigger'] ?? 'backup') === 'schedule';
        $gfs = ($data['retention'] ?? 'newest') === 'gfs';
        $targetType = (string) ($data['target_type'] ?? 'all');
        $cron = is_string($data['cron'] ?? null) ? preg_replace('/\s+/', ' ', mb_trim($data['cron'])) : '';

        return [
            'name' => mb_trim((string) ($data['name'] ?? '')),
            'enabled' => (bool) ($data['enabled'] ?? true),
            'sort' => (int) ($data['sort'] ?? 0),
            'target_type' => $targetType,
            'target_ids' => $targetType === 'all' ? [] : $ids($data['target_ids'] ?? []),
            'excluded_server_ids' => $ids($data['excluded_server_ids'] ?? []),
            'trigger' => $schedule ? 'schedule' : 'backup',
            'cron' => $schedule && $cron !== '' ? $cron : null,
            'skip_idle' => $schedule && (bool) ($data['skip_idle'] ?? false),
            'when_full' => $schedule && ($data['when_full'] ?? 'skip') === 'rotate' ? 'rotate' : 'skip',
            'destination_ids' => $ids($data['destination_ids'] ?? []),
            'retention' => $gfs ? 'gfs' : 'newest',
            'keep_newest' => $gfs ? null : $number('keep_newest'),
            'keep_daily' => $gfs ? $number('keep_daily') : null,
            'keep_weekly' => $gfs ? $number('keep_weekly') : null,
            'keep_monthly' => $gfs ? $number('keep_monthly') : null,
            'max_age_days' => $number('max_age_days'),
            'keep_local' => $number('keep_local'),
            'include_databases' => (bool) ($data['include_databases'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes  Output of normalise().
     * @return array<string, string>
     */
    public static function problems(array $attributes): array
    {
        $problems = [];

        if ($attributes['name'] === '') {
            $problems['name'] = 'Give the rule a name.';
        }
        if ($attributes['target_type'] !== 'all' && $attributes['target_ids'] === []) {
            $problems['target_ids'] = 'Pick at least one '.mb_rtrim($attributes['target_type'], 's').'.';
        }
        if ($attributes['trigger'] === 'schedule' && ($attributes['cron'] === null || ! CronExpression::isValidExpression($attributes['cron']))) {
            $problems['cron'] = 'Enter a valid cron expression, for example "0 4 * * *".';
        }
        if ($attributes['destination_ids'] === []) {
            $problems['destination_ids'] = 'Pick at least one destination.';
        }
        if ($attributes['retention'] === 'gfs' && $attributes['keep_daily'] === null && $attributes['keep_weekly'] === null && $attributes['keep_monthly'] === null) {
            $problems['keep_daily'] = 'Keep at least daily, weekly or monthly copies.';
        }
        foreach (self::NUMBERS as $key) {
            if ($attributes[$key] !== null && ($attributes[$key] < 1 || $attributes[$key] > 1000)) {
                $problems[$key] = 'Use a number from 1 to 1000.';
            }
        }

        return $problems;
    }

    /** The next time the cron expression fires after now, evaluated in the panel's timezone. */
    public static function nextRun(string $cron, DateTimeInterface $now, string $timezone): CarbonImmutable
    {
        $next = (new CronExpression($cron))->getNextRunDate(CarbonImmutable::instance($now)->setTimezone($timezone), 0, false, $timezone);

        return CarbonImmutable::instance($next)->setTimezone($timezone);
    }
}
