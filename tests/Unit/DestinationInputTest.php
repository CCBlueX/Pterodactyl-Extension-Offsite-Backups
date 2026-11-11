<?php

declare(strict_types=1);

namespace OffsiteBackups\Tests\Unit;

use OffsiteBackups\Http\Requests\DestinationInput;
use OffsiteBackups\Models\Destination;
use phpseclib3\Crypt\EC;
use PHPUnit\Framework\TestCase;

final class DestinationInputTest extends TestCase
{
    public function test_empty_secrets_keep_the_stored_ones(): void
    {
        $stored = ['key' => 'AKIA', 'secret' => 'old-secret'];
        $config = DestinationInput::config('s3', ['endpoint' => 'https://s3.example.com/', 'region' => 'eu-1', 'bucket' => 'b', 'prefix' => 'panel/', 'key' => '', 'secret' => 'new', 'path_style' => '1'], $stored);

        $this->assertSame('AKIA', $config['key']);
        $this->assertSame('new', $config['secret']);
        $this->assertSame('https://s3.example.com', $config['endpoint']);
        $this->assertSame('panel', $config['prefix']);
        $this->assertTrue($config['path_style']);
        $this->assertNull($config['storage_class']);
    }

    public function test_unknown_keys_are_dropped_and_sftp_values_are_cast(): void
    {
        $config = DestinationInput::config('sftp', ['host' => ' sftp.example.com ', 'port' => '2222', 'username' => 'u', 'password' => null, 'path' => '/backups/', 'extra' => 'x'], ['password' => 'pw']);

        $this->assertSame(['host', 'port', 'username', 'password', 'private_key', 'key_passphrase', 'path'], array_keys($config));
        $this->assertSame('sftp.example.com', $config['host']);
        $this->assertSame(2222, $config['port']);
        $this->assertSame('pw', $config['password']);
        $this->assertSame('/backups', $config['path']);
    }

    public function test_s3_config_problems(): void
    {
        $good = DestinationInput::config('s3', ['endpoint' => 'https://s3.example.com', 'region' => 'us-east-1', 'bucket' => 'my-backups', 'key' => 'k', 'secret' => 's'], []);
        $this->assertSame([], DestinationInput::problems('s3', $good));
        $this->assertSame([], DestinationInput::problems('s3', [...$good, 'endpoint' => '']));

        $bad = DestinationInput::problems('s3', [...$good, 'endpoint' => 'ftp://x', 'region' => '', 'bucket' => 'My_Bucket', 'prefix' => '/abs', 'key' => null, 'secret' => '', 'storage_class' => 'cold!']);
        $this->assertSame(['config.endpoint', 'config.region', 'config.bucket', 'config.prefix', 'config.key', 'config.secret', 'config.storage_class'], array_keys($bad));
        $this->assertArrayHasKey('config.prefix', DestinationInput::problems('s3', [...$good, 'prefix' => 'a/../b']));
    }

    public function test_bucket_names(): void
    {
        $this->assertTrue(DestinationInput::bucketName('abc'));
        $this->assertTrue(DestinationInput::bucketName('my.bucket-1'));
        $this->assertFalse(DestinationInput::bucketName('ab'));
        $this->assertFalse(DestinationInput::bucketName('-abc'));
        $this->assertFalse(DestinationInput::bucketName('a..b'));
        $this->assertFalse(DestinationInput::bucketName('192.168.1.1'));
        $this->assertFalse(DestinationInput::bucketName(str_repeat('a', 64)));
    }

    public function test_sftp_config_problems(): void
    {
        $good = ['host' => 'sftp.example.com', 'port' => 22, 'username' => 'backup', 'password' => 'pw', 'private_key' => null, 'key_passphrase' => null, 'path' => '/backups'];
        $this->assertSame([], DestinationInput::problems('sftp', $good));
        $this->assertSame([], DestinationInput::problems('sftp', [...$good, 'host' => '10.0.0.5']));

        $bad = DestinationInput::problems('sftp', ['host' => 'bad host', 'port' => 70000, 'username' => '', 'password' => null, 'private_key' => null, 'key_passphrase' => null, 'path' => 'relative']);
        $this->assertSame(['config.host', 'config.port', 'config.username', 'config.path', 'config.password'], array_keys($bad));
    }

    public function test_private_keys_must_be_readable(): void
    {
        $key = EC::createKey('Ed25519')->toString('OpenSSH');
        $good = ['host' => 'h', 'port' => 22, 'username' => 'u', 'password' => null, 'private_key' => $key, 'key_passphrase' => null, 'path' => '/'];

        $this->assertSame([], DestinationInput::problems('sftp', $good));
        $this->assertArrayHasKey('config.private_key', DestinationInput::problems('sftp', [...$good, 'private_key' => 'not a key']));
    }

    public function test_secrets_are_replaced_by_flags(): void
    {
        $shown = DestinationInput::shown('sftp', ['host' => 'h', 'password' => 'pw', 'private_key' => null, 'key_passphrase' => '']);

        $this->assertSame(['host' => 'h', 'password_set' => true, 'private_key_set' => false, 'key_passphrase_set' => false], $shown);
        $this->assertSame(['bucket' => 'b', 'key_set' => true, 'secret_set' => false], DestinationInput::shown('s3', ['bucket' => 'b', 'key' => 'k']));
    }

    public function test_format_and_encryption_are_locked_once_copies_exist(): void
    {
        $existing = new Destination();
        $existing->setRawAttributes(['kind' => 's3', 'format' => 'archive', 'encrypted' => 0]);
        $same = ['kind' => 's3', 'format' => 'archive', 'encrypted' => false, 'passphrase' => null];

        $this->assertNull(DestinationInput::lockedChange($existing, $same));
        $this->assertNotNull(DestinationInput::lockedChange($existing, [...$same, 'format' => 'dedup']));
        $this->assertNotNull(DestinationInput::lockedChange($existing, [...$same, 'encrypted' => true, 'passphrase' => 'long enough']));
        $this->assertNotNull(DestinationInput::lockedChange($existing, [...$same, 'kind' => 'sftp']));
    }
}
