<?php

declare(strict_types=1);

namespace OffsiteBackups\Http\Requests;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OffsiteBackups\Models\Destination;
use phpseclib3\Crypt\PublicKeyLoader;
use Throwable;

/**
 * Turns the destination form into model attributes; secrets left empty keep the stored value.
 */
final readonly class DestinationInput
{
    public const array SECRETS = ['s3' => ['key', 'secret'], 'sftp' => ['password', 'private_key', 'key_passphrase']];

    /**
     * Validates the request against an optional stored destination and returns model attributes.
     *
     * @return array{name: string, kind: string, format: string, encrypted: bool, passphrase: ?string, config: array<string, mixed>}
     */
    public static function validate(Request $request, ?Destination $existing = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'kind' => ['required', 'string', 'in:'.implode(',', Destination::KINDS)],
            'format' => ['required', 'string', 'in:'.implode(',', Destination::FORMATS)],
            'encrypted' => ['required', 'boolean'],
            'passphrase' => ['nullable', 'string', 'max:1024'],
            'config' => ['required', 'array'],
        ]);

        $kind = (string) $data['kind'];
        $stored = $existing !== null && $existing->kind === $kind ? (array) $existing->config : [];
        $config = self::config($kind, (array) $data['config'], $stored);
        $problems = self::problems($kind, $config);

        $encrypted = (bool) $data['encrypted'];
        $passphrase = self::filled($data['passphrase'] ?? null) ? (string) $data['passphrase'] : null;
        if ($encrypted && $passphrase === null) {
            $passphrase = $existing?->encrypted ? $existing->passphrase : null;
        }
        if ($encrypted && ($passphrase === null || mb_strlen($passphrase, '8bit') < 8)) {
            $problems['passphrase'] = 'Encrypted destinations need a passphrase of at least 8 characters.';
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        return [
            'name' => mb_trim((string) $data['name']),
            'kind' => $kind,
            'format' => (string) $data['format'],
            'encrypted' => $encrypted,
            'passphrase' => $encrypted ? $passphrase : null,
            'config' => $config,
        ];
    }

    /**
     * Keeps the known keys of a kind, trims and casts them; empty secrets fall back to the stored ones.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function config(string $kind, array $input, array $stored): array
    {
        $text = static fn (string $key): string => is_scalar($input[$key] ?? null) ? mb_trim((string) $input[$key]) : '';
        $secret = static fn (string $key): ?string => self::filled($input[$key] ?? null)
            ? (string) $input[$key]
            : (self::filled($stored[$key] ?? null) ? (string) $stored[$key] : null);

        if ($kind === 's3') {
            return [
                'endpoint' => mb_rtrim($text('endpoint'), '/'),
                'region' => $text('region'),
                'bucket' => $text('bucket'),
                'prefix' => mb_trim($text('prefix'), '/') === '' ? '' : mb_rtrim($text('prefix'), '/'),
                'key' => $secret('key') !== null ? mb_trim($secret('key')) : null,
                'secret' => $secret('secret'),
                'path_style' => filter_var($input['path_style'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'storage_class' => $text('storage_class') !== '' ? mb_strtoupper($text('storage_class')) : null,
            ];
        }

        $path = $text('path');

        return [
            'host' => $text('host'),
            'port' => is_numeric($input['port'] ?? null) ? (int) $input['port'] : 22,
            'username' => $text('username'),
            'password' => $secret('password'),
            'private_key' => $secret('private_key') !== null ? mb_trim($secret('private_key'))."\n" : null,
            'key_passphrase' => $secret('key_passphrase'),
            'path' => $path === '/' ? '/' : mb_rtrim($path, '/'),
        ];
    }

    /**
     * Field errors for a normalised config, keyed like the request ("config.bucket").
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public static function problems(string $kind, array $config): array
    {
        $problems = [];

        if ($kind === 's3') {
            $endpoint = (string) $config['endpoint'];
            if ($endpoint !== '' && (filter_var($endpoint, FILTER_VALIDATE_URL) === false || ! in_array(mb_strtolower((string) parse_url($endpoint, PHP_URL_SCHEME)), ['http', 'https'], true))) {
                $problems['config.endpoint'] = 'The endpoint must be an http or https URL, for example https://s3.eu-central-1.amazonaws.com.';
            }
            if (preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', (string) $config['region']) !== 1) {
                $problems['config.region'] = 'Enter the region, for example us-east-1 or auto.';
            }
            if (! self::bucketName((string) $config['bucket'])) {
                $problems['config.bucket'] = 'Bucket names have 3 to 63 lowercase letters, digits, dots or hyphens and start and end with a letter or digit.';
            }
            $prefix = (string) $config['prefix'];
            if ($prefix !== '' && (str_starts_with($prefix, '/') || str_contains($prefix, '//') || mb_strlen($prefix) > 200 || in_array('..', explode('/', $prefix), true) || preg_match('/[\x00-\x1f\\\\]/', $prefix) === 1)) {
                $problems['config.prefix'] = 'The prefix is a folder path like "backups/panel" without a leading slash.';
            }
            if (! self::filled($config['key'])) {
                $problems['config.key'] = 'Enter the access key.';
            }
            if (! self::filled($config['secret'])) {
                $problems['config.secret'] = 'Enter the secret key.';
            }
            if ($config['storage_class'] !== null && preg_match('/^[A-Z_]{1,32}$/', (string) $config['storage_class']) !== 1) {
                $problems['config.storage_class'] = 'Use a storage class name such as STANDARD or STANDARD_IA.';
            }

            return $problems;
        }

        if (! self::hostName((string) $config['host'])) {
            $problems['config.host'] = 'Enter a host name or IP address.';
        }
        if ($config['port'] < 1 || $config['port'] > 65535) {
            $problems['config.port'] = 'The port is a number from 1 to 65535.';
        }
        if (preg_match('/^[^\s:]{1,64}$/', (string) $config['username']) !== 1) {
            $problems['config.username'] = 'Enter the user name.';
        }
        $path = (string) $config['path'];
        if (! str_starts_with($path, '/') || in_array('..', explode('/', $path), true) || mb_strlen($path) > 255 || preg_match('/[\x00-\x1f]/', $path) === 1) {
            $problems['config.path'] = 'The path must be absolute, for example /backups.';
        }
        if (! self::filled($config['password']) && ! self::filled($config['private_key'])) {
            $problems['config.password'] = 'Enter a password or a private key.';
        } elseif (self::filled($config['private_key']) && ! self::readableKey((string) $config['private_key'], $config['key_passphrase'])) {
            $problems['config.private_key'] = 'The private key cannot be read. Check the key and its passphrase.';
        }

        return $problems;
    }

    /**
     * Why the stored destination cannot take these attributes once it holds copies, or null.
     *
     * @param  array{kind: string, format: string, encrypted: bool, passphrase: ?string}  $attributes
     */
    public static function lockedChange(Destination $existing, array $attributes): ?string
    {
        return match (true) {
            $attributes['kind'] !== $existing->kind => 'The type cannot change while the destination holds copies.',
            $attributes['format'] !== $existing->format => 'The format cannot change while the destination holds copies.',
            $attributes['encrypted'] !== $existing->encrypted => 'Encryption cannot be turned on or off while the destination holds copies.',
            $attributes['encrypted'] && $attributes['passphrase'] !== $existing->passphrase => 'The passphrase cannot change while the destination holds copies.',
            default => null,
        };
    }

    /**
     * The config as the API shows it: every secret replaced by a "<name>_set" flag.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public static function shown(string $kind, array $config): array
    {
        $shown = [];
        foreach ($config as $key => $value) {
            if (in_array($key, self::SECRETS[$kind] ?? [], true)) {
                continue;
            }
            $shown[$key] = $value;
        }
        foreach (self::SECRETS[$kind] ?? [] as $secret) {
            $shown["{$secret}_set"] = self::filled($config[$secret] ?? null);
        }

        return $shown;
    }

    public static function bucketName(string $bucket): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $bucket) === 1
            && ! str_contains($bucket, '..')
            && ! str_contains($bucket, '.-')
            && ! str_contains($bucket, '-.')
            && filter_var($bucket, FILTER_VALIDATE_IP) === false;
    }

    public static function hostName(string $host): bool
    {
        return filter_var(mb_trim($host, '[]'), FILTER_VALIDATE_IP) !== false
            || preg_match('/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $host) === 1;
    }

    private static function readableKey(string $key, mixed $passphrase): bool
    {
        try {
            PublicKeyLoader::load($key, self::filled($passphrase) ? (string) $passphrase : false);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }
}
