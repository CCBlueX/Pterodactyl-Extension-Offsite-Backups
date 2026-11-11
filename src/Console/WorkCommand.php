<?php

declare(strict_types=1);

namespace OffsiteBackups\Console;

use Illuminate\Console\Command;
use OffsiteBackups\Transfers\Worker;

final class WorkCommand extends Command
{
    protected $signature = 'offsite-backups:work {--max-time=0 : Stop starting new copies after this many seconds} {--once : Run one copy at most}';

    protected $description = 'Copy queued backups to their off-site destinations.';

    public function handle(Worker $worker): int
    {
        ini_set('memory_limit', '512M');
        set_time_limit(0);

        $done = $worker->work(max(0, (int) $this->option('max-time')), (bool) $this->option('once'), fn (string $line) => $this->line($line));

        if ($done === null) {
            $this->line('Every transfer slot is busy.');
        }

        return self::SUCCESS;
    }
}
