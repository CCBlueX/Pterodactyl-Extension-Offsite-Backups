<?php

declare(strict_types=1);

namespace OffsiteBackups\Notifications;

use OffsiteBackups\Settings;

/**
 * Posts an event to the Discord webhook when the admin asked for it.
 */
final readonly class Discord
{
    public const int GREEN = 0x22C55E;

    public const int PURPLE = 0x8B5CF6;

    public const int RED = 0xEF4444;

    public const int YELLOW = 0xEAB308;

    public const int BLUE = 0x3B82F6;

    public const array COLORS = [
        'copied' => self::GREEN,
        'restored' => self::PURPLE,
        'failed' => self::RED,
        'cleanup' => self::YELLOW,
        'pruned' => self::BLUE,
        'database' => self::PURPLE,
    ];

    public function __construct(private Settings $settings) {}

    public static function bytes(int $bytes): string
    {
        $units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
        $power = $bytes > 0 ? min(count($units) - 1, (int) floor(log($bytes, 1024))) : 0;

        return $power === 0 ? "{$bytes} B" : sprintf('%.1f %s', $bytes / (1024 ** $power), $units[$power]);
    }

    public static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 60 => "{$seconds}s",
            $seconds < 3600 => sprintf('%dm %ds', intdiv($seconds, 60), $seconds % 60),
            default => sprintf('%dh %dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60)),
        };
    }

    /** @param array<string, string|int|null> $fields */
    public function send(string $event, string $title, array $fields, ?int $color = null): void
    {
        if ($this->settings->discordWebhook() === null || ! $this->settings->notifies($event)) {
            return;
        }

        $fields = array_filter($fields, static fn (mixed $value): bool => $value !== null && $value !== '');

        SendDiscordMessage::dispatch([
            'title' => mb_substr($title, 0, 256),
            'color' => $color ?? self::COLORS[$event] ?? self::BLUE,
            'fields' => array_values(array_map(static fn (string $name, string|int $value): array => [
                'name' => mb_substr($name, 0, 256),
                'value' => mb_substr((string) $value, 0, 1024),
                'inline' => $name !== 'Error',
            ], array_keys($fields), $fields)),
            'footer' => ['text' => 'Offsite Backups'],
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
