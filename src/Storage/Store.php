<?php

declare(strict_types=1);

namespace OffsiteBackups\Storage;

use Generator;

/**
 * An S3 bucket or SFTP directory. Paths are relative to the destination root and use "/".
 */
interface Store
{
    /** Writes a whole object; integrity is checked and parent folders are created. */
    public function put(string $path, string $body): void;

    /**
     * Streams an object of any size.
     *
     * @param  iterable<string>  $chunks
     * @return int Bytes written.
     */
    public function upload(string $path, iterable $chunks, string $contentType = 'application/octet-stream'): int;

    /** @return Generator<int, string> The object in pieces of about 1 MiB. */
    public function read(string $path): Generator;

    public function readRange(string $path, int $offset, int $length): string;

    /** Null when the object does not exist. */
    public function size(string $path): ?int;

    /** A missing object is fine. */
    public function delete(string $path): void;

    /** @param list<string> $paths */
    public function deleteMany(array $paths): void;

    /** @return Generator<int, array{path: string, size: int, modified: int}> Every object below the prefix. */
    public function list(string $prefix): Generator;

    /** A short-lived download link (S3 only). */
    public function presignedGet(string $path, int $seconds, string $filename): ?string;

    /** Writes, reads back and deletes a small object. */
    public function probe(): void;
}
