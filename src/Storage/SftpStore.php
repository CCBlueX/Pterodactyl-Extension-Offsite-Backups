<?php

declare(strict_types=1);

namespace OffsiteBackups\Storage;

use Generator;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Exception\NoKeyLoadedException;
use phpseclib3\Net\SFTP;
use SensitiveParameter;
use Throwable;

final class SftpStore implements Store
{
    private const int PIECE = 1024 * 1024;

    private ?SFTP $sftp = null;

    /** @var array<string, true> */
    private array $folders = [];

    /** @param string $root Folder below which every path lives; empty for the login folder. */
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        #[SensitiveParameter] private readonly ?string $password,
        #[SensitiveParameter] private readonly ?string $privateKey,
        #[SensitiveParameter] private readonly ?string $keyPassphrase,
        private readonly string $root,
    ) {}

    public function put(string $path, string $body): void
    {
        $full = $this->path($path);

        $written = $this->attempt("write {$path}", function (SFTP $sftp) use ($full, $body): bool {
            $this->folder(dirname($full));

            return $sftp->put("{$full}.part", $body);
        });

        if (! $written) {
            throw $this->error("write {$path}");
        }

        $this->finish($path, $full, mb_strlen($body, '8bit'));
    }

    public function upload(string $path, iterable $chunks, string $contentType = 'application/octet-stream'): int
    {
        $full = $this->path($path);
        $sftp = $this->connection();
        $this->folder(dirname($full));

        $source = (static fn (): Generator => yield from $chunks)();
        $buffer = '';
        $offset = 0;
        $total = 0;
        $next = static function (int $size) use ($source, &$buffer, &$offset, &$total): ?string {
            while (mb_strlen($buffer, '8bit') - $offset < $size && $source->valid()) {
                $buffer = mb_substr($buffer, $offset, null, '8bit').$source->current();
                $offset = 0;
                $source->next();
            }

            $piece = mb_substr($buffer, $offset, $size, '8bit');
            $offset += mb_strlen($piece, '8bit');
            $total += mb_strlen($piece, '8bit');

            return $piece === '' ? null : $piece;
        };

        try {
            $written = $sftp->put("{$full}.part", $next, SFTP::SOURCE_CALLBACK);
        } catch (Throwable $exception) {
            // The connection is mid-transfer and cannot be reused.
            $this->sftp = null;
            $this->discard("{$full}.part");

            throw $this->failure($exception, "upload {$path}");
        }

        if (! $written) {
            throw $this->error("upload {$path}");
        }

        $this->finish($path, $full, $total);

        return $total;
    }

    public function read(string $path): Generator
    {
        $size = $this->size($path) ?? throw StoreException::missing($path);

        for ($offset = 0; $offset < $size; $offset += self::PIECE) {
            yield $this->readRange($path, $offset, min(self::PIECE, $size - $offset));
        }
    }

    public function readRange(string $path, int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $data = $this->attempt("read {$path}", fn (SFTP $sftp): string|bool => $sftp->get($this->path($path), false, $offset, $length));

        if (! is_string($data)) {
            throw $this->error("read {$path}");
        }

        if (mb_strlen($data, '8bit') !== $length) {
            throw new StoreException("{$path} is shorter than expected.");
        }

        return $data;
    }

    public function size(string $path): ?int
    {
        $stat = $this->attempt("check {$path}", fn (SFTP $sftp): array|false => $sftp->stat($this->path($path)));

        return is_array($stat) && isset($stat['size']) ? (int) $stat['size'] : null;
    }

    public function delete(string $path): void
    {
        $full = $this->path($path);

        if (! $this->attempt("delete {$path}", static fn (SFTP $sftp): bool => $sftp->delete($full, false)) && $this->size($path) !== null) {
            throw $this->error("delete {$path}");
        }
    }

    public function deleteMany(array $paths): void
    {
        foreach ($paths as $path) {
            $this->delete($path);
        }
    }

    public function list(string $prefix): Generator
    {
        $prefix = mb_trim($prefix, '/');

        yield from $this->walk($prefix);
    }

    public function presignedGet(string $path, int $seconds, string $filename): ?string
    {
        return null;
    }

    public function probe(): void
    {
        $path = '.offsite-backups-probe-'.bin2hex(random_bytes(6));
        $body = random_bytes(64);
        $this->put($path, $body);

        try {
            if ($this->readRange($path, 0, 64) !== $body) {
                throw new StoreException('The test file read back differs from what was written.');
            }
        } finally {
            $this->delete($path);
        }
    }

    /** @return Generator<int, array{path: string, size: int, modified: int}> */
    private function walk(string $relative): Generator
    {
        $entries = $this->attempt('list '.($relative === '' ? 'the folder' : $relative), fn (SFTP $sftp): array|false => $sftp->rawlist($relative === '' ? $this->rootFolder() : $this->path($relative)));

        if (! is_array($entries)) {
            return;
        }

        ksort($entries, SORT_STRING);

        foreach ($entries as $name => $entry) {
            $name = (string) $name;

            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $relative === '' ? $name : "{$relative}/{$name}";
            $type = (int) ($entry['type'] ?? 0);

            if ($type === 2) {
                yield from $this->walk($path);
            } elseif ($type === 1) {
                yield ['path' => $path, 'size' => (int) ($entry['size'] ?? 0), 'modified' => (int) ($entry['mtime'] ?? 0)];
            }
        }
    }

    private function finish(string $path, string $full, int $length): void
    {
        $sftp = $this->connection();
        $stat = $sftp->stat("{$full}.part");

        if (! is_array($stat) || (int) ($stat['size'] ?? -1) !== $length) {
            $this->discard("{$full}.part");

            throw new StoreException("Could not write {$path}: the server stored a different size than was sent.");
        }

        if (! $sftp->rename("{$full}.part", $full)) {
            $sftp->delete($full, false);

            if (! $sftp->rename("{$full}.part", $full)) {
                $error = $this->error("move {$path} into place");
                $this->discard("{$full}.part");

                throw $error;
            }
        }
    }

    private function discard(string $full): void
    {
        try {
            $this->connection()->delete($full, false);
        } catch (Throwable) {
        }
    }

    private function folder(string $full): void
    {
        if ($full === '' || $full === '.' || $full === '/' || isset($this->folders[$full])) {
            return;
        }

        $sftp = $this->connection();

        if (! $sftp->is_dir($full) && ! $sftp->mkdir($full, -1, true) && ! $sftp->is_dir($full)) {
            throw $this->error("create the folder {$full}");
        }

        $this->folders[$full] = true;
    }

    private function rootFolder(): string
    {
        return $this->root === '' ? '.' : $this->root;
    }

    private function path(string $path): string
    {
        $path = mb_ltrim($path, '/');

        return $this->root === '' ? $path : mb_rtrim($this->root, '/').'/'.$path;
    }

    /**
     * @template T
     *
     * @param  callable(SFTP): T  $operation
     * @return T
     */
    private function attempt(string $action, callable $operation): mixed
    {
        try {
            return $operation($this->connection());
        } catch (StoreException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->sftp = null;

            throw $this->failure($exception, $action);
        }
    }

    private function connection(): SFTP
    {
        if ($this->sftp !== null && $this->sftp->isConnected()) {
            return $this->sftp;
        }

        try {
            $credential = $this->privateKey !== null && $this->privateKey !== ''
                ? PublicKeyLoader::load($this->privateKey, $this->keyPassphrase !== null && $this->keyPassphrase !== '' ? $this->keyPassphrase : false)
                : (string) $this->password;
        } catch (NoKeyLoadedException) {
            throw new StoreException('The private key could not be read. Check the key and its passphrase.');
        }

        $sftp = new SFTP($this->host, $this->port, 15);
        $sftp->setTimeout(60);
        $sftp->setKeepAlive(30);
        $sftp->disableStatCache();

        try {
            $loggedIn = $sftp->login($this->username, $credential);
        } catch (Throwable $exception) {
            throw $this->failure($exception, "connect to {$this->host}:{$this->port}");
        }

        if (! $loggedIn) {
            throw new StoreException("The SFTP server refused the login of \"{$this->username}\". Check the user name, password or key.");
        }

        $this->folders = [];

        return $this->sftp = $sftp;
    }

    private function error(string $action): StoreException
    {
        $error = (string) $this->sftp?->getLastSFTPError();

        $reason = match (true) {
            str_contains($error, 'PERMISSION_DENIED') => 'permission denied. The SFTP user needs to read, write and delete files in this folder.',
            str_contains($error, 'NO_SUCH_FILE') => 'the file or folder does not exist.',
            str_contains($error, 'NO_SPACE_ON_FILESYSTEM'), str_contains($error, 'QUOTA_EXCEEDED') => 'the SFTP server is out of space.',
            $error !== '' => $error,
            default => 'the SFTP server reported an error.',
        };

        return new StoreException("Could not {$action}: {$reason}");
    }

    private function failure(Throwable $exception, string $action): StoreException
    {
        if ($exception instanceof StoreException) {
            return $exception;
        }

        $message = $exception->getMessage();

        $reason = match (true) {
            str_contains($message, 'getaddrinfo'), str_contains($message, 'Name or service not known'), str_contains($message, 'nodename nor servname') => 'the host name could not be found (DNS).',
            str_contains($message, 'Connection refused') => 'the server refused the connection.',
            str_contains($message, 'timed out'), str_contains($message, 'Timed out') => 'the connection timed out.',
            str_contains($message, 'Connection closed') => 'the server closed the connection.',
            default => $message,
        };

        return new StoreException("Could not {$action}: {$reason}", 0, $exception);
    }
}
