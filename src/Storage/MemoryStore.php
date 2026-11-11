<?php

declare(strict_types=1);

namespace OffsiteBackups\Storage;

use Generator;

/** A store in memory, for tests. */
final class MemoryStore implements Store
{
    /** @var array<string, array{body: string, modified: int}> */
    public array $objects = [];

    /** @var list<string> Paths written, in order. */
    public array $writes = [];

    public function put(string $path, string $body): void
    {
        $this->objects[$path] = ['body' => $body, 'modified' => time()];
        $this->writes[] = $path;
    }

    public function upload(string $path, iterable $chunks, string $contentType = 'application/octet-stream'): int
    {
        $body = '';

        foreach ($chunks as $chunk) {
            $body .= $chunk;
        }

        $this->put($path, $body);

        return mb_strlen($body, '8bit');
    }

    public function read(string $path): Generator
    {
        $body = $this->body($path);

        for ($offset = 0, $length = mb_strlen($body, '8bit'); $offset < $length; $offset += 1024 * 1024) {
            yield mb_substr($body, $offset, 1024 * 1024, '8bit');
        }
    }

    public function readRange(string $path, int $offset, int $length): string
    {
        $data = mb_substr($this->body($path), $offset, $length, '8bit');

        if (mb_strlen($data, '8bit') !== $length) {
            throw new StoreException("{$path} is shorter than expected.");
        }

        return $data;
    }

    public function size(string $path): ?int
    {
        return isset($this->objects[$path]) ? mb_strlen($this->objects[$path]['body'], '8bit') : null;
    }

    public function delete(string $path): void
    {
        unset($this->objects[$path]);
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
        ksort($this->objects, SORT_STRING);

        foreach ($this->objects as $path => $object) {
            if ($prefix === '' || str_starts_with($path, "{$prefix}/")) {
                yield ['path' => $path, 'size' => mb_strlen($object['body'], '8bit'), 'modified' => $object['modified']];
            }
        }
    }

    public function presignedGet(string $path, int $seconds, string $filename): ?string
    {
        return null;
    }

    public function probe(): void {}

    private function body(string $path): string
    {
        return $this->objects[$path]['body'] ?? throw StoreException::missing($path);
    }
}
