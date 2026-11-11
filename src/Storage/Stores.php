<?php

declare(strict_types=1);

namespace OffsiteBackups\Storage;

use Aws\S3\S3Client;
use OffsiteBackups\Models\Destination;
use OffsiteBackups\Settings;
use OffsiteBackups\Support\Hosts;

final readonly class Stores
{
    /** @param bool $bucketRoot Ignore the prefix, for backups Wings wrote straight to the bucket. */
    public static function for(Destination $destination, bool $bucketRoot = false): Store
    {
        return self::fromConfig($destination->kind, (array) $destination->config, $bucketRoot);
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(string $kind, array $config, bool $bucketRoot = false, ?bool $allowPrivate = null, ?int $partSize = null): Store
    {
        $allowPrivate ??= app(Settings::class)->allowPrivateHosts();

        return match ($kind) {
            's3' => self::s3($config, $bucketRoot, $allowPrivate, $partSize ?? app(Settings::class)->partSize()),
            'sftp' => self::sftp($config, $allowPrivate),
            default => throw new StoreException("Unknown destination type \"{$kind}\"."),
        };
    }

    /** @param array<string, mixed> $config */
    private static function s3(array $config, bool $bucketRoot, bool $allowPrivate, int $partSize): S3Store
    {
        $endpoint = mb_rtrim(mb_trim((string) ($config['endpoint'] ?? '')), '/');
        $bucket = mb_trim((string) ($config['bucket'] ?? ''));
        $region = mb_trim((string) ($config['region'] ?? ''));

        if ($bucket === '') {
            throw new StoreException('Enter a bucket name.');
        }

        if ($endpoint !== '' && ($problem = Hosts::urlProblem($endpoint, $allowPrivate)) !== null) {
            throw new StoreException("S3 endpoint: {$problem}");
        }

        $key = (string) ($config['key'] ?? '');
        $secret = (string) ($config['secret'] ?? '');

        $client = new S3Client([
            'version' => 'latest',
            'region' => $region !== '' ? $region : 'us-east-1',
            'use_path_style_endpoint' => (bool) ($config['path_style'] ?? false),
            'credentials' => $key !== '' ? ['key' => $key, 'secret' => $secret] : false,
            'retries' => 3,
            'http' => ['connect_timeout' => 10, 'read_timeout' => 120, 'timeout' => 0],
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ] + ($endpoint !== '' ? ['endpoint' => $endpoint] : []));

        $storageClass = mb_trim((string) ($config['storage_class'] ?? ''));

        return new S3Store(
            $client,
            $bucket,
            $bucketRoot ? '' : mb_trim((string) ($config['prefix'] ?? ''), '/'),
            $partSize,
            $storageClass !== '' ? $storageClass : null,
        );
    }

    /** @param array<string, mixed> $config */
    private static function sftp(array $config, bool $allowPrivate): SftpStore
    {
        $host = mb_trim((string) ($config['host'] ?? ''));

        if (($problem = Hosts::hostProblem($host, $allowPrivate)) !== null) {
            throw new StoreException("SFTP host: {$problem}");
        }

        $username = (string) ($config['username'] ?? '');

        if ($username === '') {
            throw new StoreException('Enter the SFTP user name.');
        }

        $port = (int) ($config['port'] ?? 22);
        $path = mb_trim((string) ($config['path'] ?? ''));
        $root = $path === '/' ? '/' : mb_rtrim($path, '/');

        return new SftpStore(
            $host,
            $port > 0 && $port < 65536 ? $port : 22,
            $username,
            isset($config['password']) ? (string) $config['password'] : null,
            isset($config['private_key']) ? (string) $config['private_key'] : null,
            isset($config['key_passphrase']) ? (string) $config['key_passphrase'] : null,
            $root,
        );
    }
}
