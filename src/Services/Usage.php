<?php

declare(strict_types=1);

namespace OffsiteBackups\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use OffsiteBackups\Models\Copy;
use OffsiteBackups\Models\Destination;
use OffsiteBackups\Models\Rule;
use OffsiteBackups\Rules\Matcher;
use OffsiteBackups\Settings;
use Pterodactyl\Models\Server;

final readonly class Usage
{
    /** @return array{copies: int, stored_bytes: int, failed: int, last_copy_at: ?string, destinations: list<string>, protected: bool, policy: ?string} */
    public static function forServer(Server|int $server): array
    {
        $id = $server instanceof Server ? (int) $server->id : $server;
        $model = $server instanceof Server ? $server : Server::query()->find($id);

        $verified = Copy::query()->where('server_id', $id)->where('status', 'verified');
        $last = (clone $verified)->max('finished_at');

        $rules = $model !== null ? Matcher::rulesFor($model) : collect();
        $ids = $rules->flatMap(static fn (Rule $rule): array => $rule->destination_ids ?? [])
            ->merge(Copy::query()->where('server_id', $id)->where('status', 'verified')->distinct()->pluck('destination_id'))
            ->map(intval(...))
            ->unique()
            ->values()
            ->all();
        $enabled = Allowance::for($id)['enabled'];

        return [
            'copies' => (clone $verified)->count(),
            'stored_bytes' => (int) (clone $verified)->sum('stored_bytes'),
            'failed' => Copy::query()->where('server_id', $id)->where('status', 'failed')->count(),
            'last_copy_at' => $last !== null ? CarbonImmutable::parse($last)->toAtomString() : null,
            'destinations' => $ids === [] ? [] : Destination::query()->whereKey($ids)->orderBy('name')->pluck('name')->all(),
            'protected' => $enabled && $rules->isNotEmpty(),
            'policy' => $enabled && $rules->isNotEmpty() ? self::policy($rules->first()) : null,
        ];
    }

    /**
     * @return array{totals: array{queued: int, running: int, verified: int, failed: int}, stored_bytes: int, copies_24h: int, failed_24h: int, worker: array{mode: string, seen_at: ?string}, destinations: list<array<string, mixed>>}
     */
    public static function overview(): array
    {
        $counts = array_map(static fn (string $status): int => Copy::query()->where('status', $status)->count(), array_combine(Copy::STATUSES, Copy::STATUSES));
        $since = now()->subDay();

        return [
            'totals' => $counts,
            'stored_bytes' => (int) Copy::query()->where('status', 'verified')->sum('stored_bytes'),
            'copies_24h' => Copy::query()->where('status', 'verified')->where('finished_at', '>=', $since)->count(),
            'failed_24h' => Copy::query()->where('status', 'failed')->where('updated_at', '>=', $since)->count(),
            'worker' => [
                'mode' => app(Settings::class)->dedicatedWorker() ? 'dedicated' : 'scheduler',
                'seen_at' => self::timestamp(cache()->get('offsite-backups:worker:seen')),
            ],
            'destinations' => Destination::query()
                ->withCount(['copies' => static fn ($query) => $query->where('status', 'verified')])
                ->withSum(['copies' => static fn ($query) => $query->where('status', 'verified')], 'stored_bytes')
                ->orderBy('name')
                ->get()
                ->map(static fn (Destination $destination): array => [
                    'id' => $destination->id,
                    'name' => $destination->name,
                    'kind' => $destination->kind,
                    'format' => $destination->format,
                    'encrypted' => $destination->encrypted,
                    'status' => $destination->status,
                    'copies' => (int) $destination->copies_count,
                    'stored_bytes' => (int) ($destination->copies_sum_stored_bytes ?? 0),
                ])
                ->all(),
        ];
    }

    /** Short description of a rule's retention, such as "Newest 7" or "7 daily, 4 weekly". */
    public static function policy(Rule $rule): string
    {
        if ($rule->retention === 'gfs') {
            $parts = array_filter([
                $rule->keep_daily !== null ? "{$rule->keep_daily} daily" : null,
                $rule->keep_weekly !== null ? "{$rule->keep_weekly} weekly" : null,
                $rule->keep_monthly !== null ? "{$rule->keep_monthly} monthly" : null,
            ]);
            $text = $parts === [] ? 'All copies' : implode(', ', $parts);
        } else {
            $text = $rule->keep_newest !== null ? "Newest {$rule->keep_newest}" : 'All copies';
        }

        return $rule->max_age_days !== null ? "{$text}, up to {$rule->max_age_days} days" : $text;
    }

    private static function timestamp(mixed $value): ?string
    {
        return match (true) {
            $value instanceof DateTimeInterface => CarbonImmutable::instance($value)->toAtomString(),
            is_int($value), is_string($value) && ctype_digit($value) => CarbonImmutable::createFromTimestamp((int) $value)->toAtomString(),
            is_string($value) && $value !== '' => CarbonImmutable::parse($value)->toAtomString(),
            default => null,
        };
    }
}
