<?php

declare(strict_types=1);

namespace OffsiteBackups\Tests\Unit;

use OffsiteBackups\Storage\StoreException;
use OffsiteBackups\Storage\Stores;
use OffsiteBackups\Support\Hosts;
use PHPUnit\Framework\TestCase;

final class HostsTest extends TestCase
{
    public function test_urls_need_https_and_public_addresses(): void
    {
        $this->assertNull(Hosts::urlProblem('https://1.1.1.1', false));
        $this->assertSame('Use an https URL.', Hosts::urlProblem('http://1.1.1.1', false));
        $this->assertSame('Use an https URL.', Hosts::urlProblem('ftp://1.1.1.1', false));
        $this->assertStringContainsString('private address', (string) Hosts::urlProblem('https://10.0.0.5:9000', false));
        $this->assertStringContainsString('private address', (string) Hosts::urlProblem('https://[::1]', false));
        $this->assertNull(Hosts::urlProblem('http://10.0.0.5:9000', true));
    }

    public function test_hosts_are_checked(): void
    {
        $this->assertNull(Hosts::hostProblem('8.8.8.8', false));
        $this->assertStringContainsString('private address', (string) Hosts::hostProblem('192.168.1.10', false));
        $this->assertStringContainsString('private address', (string) Hosts::hostProblem('127.0.0.1', false));
        $this->assertNull(Hosts::hostProblem('192.168.1.10', true));
        $this->assertSame('Enter a host name or IP address.', Hosts::hostProblem('', true));
        $this->assertSame('Enter a host name or IP address.', Hosts::hostProblem('user@host', true));
    }

    public function test_stores_refuse_private_endpoints(): void
    {
        $this->expectException(StoreException::class);
        $this->expectExceptionMessage('S3 endpoint: ');

        Stores::fromConfig('s3', ['endpoint' => 'https://172.16.0.1', 'bucket' => 'b'], allowPrivate: false, partSize: 8 * 1024 * 1024);
    }

    public function test_stores_build_both_kinds(): void
    {
        $this->assertNotNull(Stores::fromConfig('s3', ['endpoint' => 'http://172.16.0.1:8333', 'bucket' => 'b', 'path_style' => true], allowPrivate: true, partSize: 8 * 1024 * 1024));
        $this->assertNotNull(Stores::fromConfig('sftp', ['host' => '172.16.0.1', 'username' => 'u', 'password' => 'p', 'path' => '/backups/'], allowPrivate: true));
    }
}
