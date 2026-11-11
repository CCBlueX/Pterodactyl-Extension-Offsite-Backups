<?php

declare(strict_types=1);

namespace OffsiteBackups\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use OffsiteBackups\Settings;
use RuntimeException;

final class SendDiscordMessage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @param array<string, mixed> $embed */
    public function __construct(public array $embed)
    {
        $this->onQueue('low');
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(Settings $settings): void
    {
        $webhook = $settings->discordWebhook();

        if ($webhook === null) {
            return;
        }

        $response = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->acceptJson()->post($webhook, [
            'username' => 'Offsite Backups',
            'embeds' => [$this->embed],
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Discord answered with HTTP {$response->status()}.");
        }
    }
}
