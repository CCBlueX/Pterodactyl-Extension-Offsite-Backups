<?php

declare(strict_types=1);

namespace OffsiteBackups\Console;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use OffsiteBackups\Models\Rule;
use OffsiteBackups\Models\Run;
use OffsiteBackups\Panel\Panel;
use OffsiteBackups\Rules\Matcher;
use OffsiteBackups\Services\Allowance;
use OffsiteBackups\Settings;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class TickCommand extends Command
{
    /** A backup that Wings has not reported after this long is given up on. */
    private const int STARTED_HOURS = 12;

    /** A run the panel's backup throttle keeps refusing is given up on after this long. */
    private const int THROTTLED_MINUTES = 60;

    protected $signature = 'offsite-backups:tick';

    protected $description = 'Plan and start the backups of scheduled off-site rules.';

    public function handle(Panel $panel, Settings $settings): int
    {
        ini_set('memory_limit', '512M');

        $now = CarbonImmutable::now();

        $this->plan($now);
        $this->start($panel, $settings->scheduleBatch(), $now);

        Run::query()->where('status', 'started')->where('updated_at', '<', $now->subHours(self::STARTED_HOURS))
            ->update(['status' => 'failed', 'message' => 'Wings never reported the backup as finished.', 'updated_at' => $now]);

        return self::SUCCESS;
    }

    /** Due rules get a planned run for every server they apply to. */
    private function plan(CarbonImmutable $now): void
    {
        $rules = Rule::query()->where('enabled', true)->where('trigger', 'schedule')->whereNotNull('cron')
            ->where(static fn (Builder $query) => $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now))
            ->get();

        foreach ($rules as $rule) {
            try {
                if (! CronExpression::isValidExpression((string) $rule->cron)) {
                    continue;
                }

                $next = CarbonImmutable::instance((new CronExpression((string) $rule->cron))->getNextRunDate($now->toDateTime(), 0, false, config('app.timezone')));

                // A rule without a next run was just created or enabled: it starts at its next slot.
                if ($rule->next_run_at !== null) {
                    $this->planRuns($rule, $now);
                    $rule->last_run_at = $now;
                }

                $rule->next_run_at = $next;
                $rule->save();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function planRuns(Rule $rule, CarbonImmutable $now): void
    {
        $busy = Run::query()->where('rule_id', $rule->id)->whereIn('status', ['planned', 'started'])->pluck('server_id')->map(intval(...))->all();
        $servers = Matcher::servers($rule)->toBase()->orderBy('servers.id')->pluck('servers.id')->map(intval(...))->diff($busy);

        foreach ($servers->chunk(500) as $chunk) {
            Run::query()->insert($chunk->map(static fn (int $id): array => [
                'rule_id' => $rule->id,
                'server_id' => $id,
                'status' => 'planned',
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all());
        }
    }

    /** Starts a few planned runs; the rest wait for the next minute so nodes are not asked for everything at once. */
    private function start(Panel $panel, int $batch, CarbonImmutable $now): void
    {
        $runs = Run::query()->with('rule')->where('status', 'planned')->orderBy('updated_at')->orderBy('id')->limit($batch)->get();

        foreach ($runs as $run) {
            try {
                $this->startRun($panel, $run, $now);
            } catch (Throwable $exception) {
                report($exception);
                $run->update(['status' => 'failed', 'message' => Str::limit($exception->getMessage(), 1000)]);
            }
        }
    }

    private function startRun(Panel $panel, Run $run, CarbonImmutable $now): void
    {
        $rule = $run->rule;
        $server = $panel->server($run->server_id);

        if ($rule === null || ! $rule->enabled || $server === null) {
            $run->update(['status' => 'skipped', 'message' => 'The rule or the server is gone or disabled.']);

            return;
        }

        if (! Allowance::for($server)['enabled']) {
            $run->update(['status' => 'skipped', 'message' => 'Off-site copies are turned off for this server.']);

            return;
        }

        if ($rule->skip_idle) {
            $latest = $panel->latestBackup($server);

            if ($latest?->completed_at !== null && $panel->isIdle($server, $latest->completed_at)) {
                $run->update(['status' => 'skipped', 'message' => 'Nothing changed since the last backup.']);

                return;
            }
        }

        try {
            $backup = $panel->initiateBackup($server, Str::limit("Off-site: {$rule->name}", 191, ''), $rule->when_full === 'rotate');
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() === 429 && $run->created_at->gt($now->subMinutes(self::THROTTLED_MINUTES))) {
                $run->update(['message' => 'Waiting for the panel\'s backup throttle.', 'updated_at' => $now]);

                return;
            }

            $run->update(['status' => 'failed', 'message' => Str::limit($exception->getMessage(), 1000)]);

            return;
        }

        $run->update(['status' => 'started', 'backup_uuid' => $backup->uuid, 'message' => null]);
    }
}
