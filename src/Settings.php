<?php

declare(strict_types=1);

namespace OffsiteBackups;

use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Services\Extensions\ExtensionSettingDefinition;
use Pterodactyl\Services\Extensions\ExtensionSettings;
use Pterodactyl\Services\Extensions\ExtensionSettingsRegistry;

final readonly class Settings
{
    public const string ID = 'offsite-backups';

    public const array EVENTS = [
        'copied' => 'Copy finished',
        'failed' => 'Copy failed for good',
        'restored' => 'Restore from off-site finished',
        'cleanup' => 'Copies of deleted servers removed',
        'pruned' => 'Deduplicated storage pruned',
        'database' => 'Database dump stored',
    ];

    private const int MIB = 1024 * 1024;

    public function __construct(private ExtensionSettingsRegistry $registry) {}

    /** @return list<ExtensionSettingDefinition> */
    public static function definitions(): array
    {
        return [
            ExtensionSettingDefinition::make('worker', 'worker', 'scheduler', ['required', 'in:scheduler,dedicated'])
                ->tab('Transfers')
                ->label('Transfer worker')
                ->help('Scheduler: the panel\'s scheduler starts a background worker every minute, nothing to set up. Dedicated: run "php artisan offsite-backups:work" yourself (supervisor, systemd or a container).')
                ->field('select', [['value' => 'scheduler', 'label' => 'Panel scheduler (recommended)'], ['value' => 'dedicated', 'label' => 'Dedicated worker']]),
            ExtensionSettingDefinition::make('concurrency', 'concurrency', 1, ['required', 'integer', 'min:1', 'max:4'])
                ->tab('Transfers')
                ->label('Parallel transfers')
                ->help('Copies that run at the same time. Each one streams a backup from its node.')
                ->field('number'),
            ExtensionSettingDefinition::make('attempts', 'attempts', 5, ['required', 'integer', 'min:1', 'max:10'])
                ->tab('Transfers')
                ->label('Attempts per copy')
                ->help('A failed copy is retried after 1, 4, 16, 64 ... minutes before it is marked failed.')
                ->field('number'),
            ExtensionSettingDefinition::make('part_size', 'part_size', 64, ['required', 'integer', 'min:8', 'max:512'])
                ->tab('Transfers')
                ->label('Upload part size (MiB)')
                ->help('Archive copies go to S3 in parts of this size. Bigger parts mean fewer requests.')
                ->field('number'),
            ExtensionSettingDefinition::make('schedule_batch', 'schedule_batch', 3, ['required', 'integer', 'min:1', 'max:50'])
                ->tab('Transfers')
                ->label('Scheduled backups per minute')
                ->help('Scheduled rules start at most this many backups each minute, so a node is not asked for all of them at once.')
                ->field('number'),
            ExtensionSettingDefinition::make('default_enabled', 'default_enabled', true, ['boolean'])
                ->tab('Servers')
                ->label('Copy backups of every server')
                ->help('Servers without their own off-site setting follow this.')
                ->field('toggle'),
            ExtensionSettingDefinition::make('orphan_grace_days', 'orphan_grace_days', 7, ['required', 'integer', 'min:0', 'max:365'])
                ->tab('Servers')
                ->label('Keep copies of deleted servers (days)')
                ->help('Copies of a deleted server are removed after this many days. 0 removes them the next night.')
                ->field('number'),
            ExtensionSettingDefinition::make('link_lifetime', 'link_lifetime', 30, ['required', 'integer', 'min:5', 'max:1440'])
                ->tab('Links')
                ->label('Download link lifetime (minutes)')
                ->help('Download and restore links work once and expire after this many minutes.')
                ->field('number'),
            ExtensionSettingDefinition::make('links_require_user', 'links_require_user', true, ['boolean'])
                ->tab('Links')
                ->label('Download links require the same signed-in user')
                ->help('Refuse a download link opened in a browser where the user who asked for it is not signed in.')
                ->field('toggle'),
            ExtensionSettingDefinition::make('node_panel_url', 'node_panel_url', '', ['nullable', 'string', 'max:255', 'url:https,http'])
                ->tab('Links')
                ->label('Panel URL for nodes')
                ->help('Restores of encrypted, deduplicated or SFTP copies give Wings a link to the panel. Leave empty to use the panel URL.')
                ->field('text'),
            ExtensionSettingDefinition::make('allow_private_hosts', 'allow_private_hosts', false, ['boolean'])
                ->tab('Links')
                ->label('Allow private destinations')
                ->help('Lets S3 endpoints and SFTP hosts point to private networks, for example a MinIO on the same host.')
                ->field('toggle'),
            ExtensionSettingDefinition::make('discord_webhook', 'discord_webhook', '', ['nullable', 'string', 'max:255', 'regex:#^https://(discord\.com|discordapp\.com|ptb\.discord\.com|canary\.discord\.com)/api/webhooks/#'])
                ->tab('Notifications')
                ->label('Discord webhook')
                ->help('Posts a message for the events below.')
                ->secret(),
            ExtensionSettingDefinition::make('discord_events', 'discord_events', ['failed', 'restored', 'cleanup'], ['array'])
                ->tab('Notifications')
                ->label('Notify about')
                ->multiselect(array_map(static fn (string $value, string $label): array => ['value' => $value, 'label' => $label], array_keys(self::EVENTS), self::EVENTS)),
        ];
    }

    public function dedicatedWorker(): bool
    {
        return $this->get('worker', 'scheduler') === 'dedicated';
    }

    public function concurrency(): int
    {
        return min(4, max(1, (int) $this->get('concurrency', 1)));
    }

    public function attempts(): int
    {
        return min(10, max(1, (int) $this->get('attempts', 5)));
    }

    public function partSize(): int
    {
        return min(512, max(8, (int) $this->get('part_size', 64))) * self::MIB;
    }

    public function scheduleBatch(): int
    {
        return min(50, max(1, (int) $this->get('schedule_batch', 3)));
    }

    public function defaultEnabled(): bool
    {
        return (bool) $this->get('default_enabled', true);
    }

    public function orphanGraceDays(): int
    {
        return max(0, (int) $this->get('orphan_grace_days', 7));
    }

    public function linkLifetime(): int
    {
        return min(1440, max(5, (int) $this->get('link_lifetime', 30)));
    }

    public function linksRequireUser(): bool
    {
        return (bool) $this->get('links_require_user', true);
    }

    /** Base URL Wings uses to reach the panel, without a trailing slash. */
    public function nodePanelUrl(): string
    {
        $url = mb_trim((string) $this->get('node_panel_url', ''));

        return mb_rtrim($url !== '' ? $url : (string) config('app.url'), '/');
    }

    public function allowPrivateHosts(): bool
    {
        return (bool) $this->get('allow_private_hosts', false);
    }

    public function discordWebhook(): ?string
    {
        $url = mb_trim((string) $this->get('discord_webhook', ''));

        return $url !== '' ? $url : null;
    }

    public function notifies(string $event): bool
    {
        return in_array($event, (array) $this->get('discord_events', ['failed', 'restored', 'cleanup']), true);
    }

    /** The destination Wings writes new backups to when a server follows the panel default; null keeps the panel's own configuration. */
    public function storageDestinationId(): ?int
    {
        $id = $this->managed()->get('storage_destination_id');

        return is_numeric($id) ? (int) $id : null;
    }

    public function setStorageDestinationId(?int $id): void
    {
        $id === null ? $this->managed()->forget('storage_destination_id') : $this->managed()->set('storage_destination_id', $id);
    }

    private function managed(): ExtensionSettings
    {
        return app(ExtensionRepository::class)->settings(self::ID);
    }

    private function get(string $key, mixed $default): mixed
    {
        return $this->registry->get(self::ID)?->get($key) ?? $default;
    }
}
