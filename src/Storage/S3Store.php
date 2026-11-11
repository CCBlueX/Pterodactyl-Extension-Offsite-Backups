<?php

declare(strict_types=1);

namespace OffsiteBackups\Storage;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Generator;
use HashContext;
use Throwable;

final readonly class S3Store implements Store
{
    private const int PIECE = 1024 * 1024;

    private const int MAX_PARTS = 10000;

    /** @param string $prefix Without leading or trailing slashes; empty for the bucket root. */
    public function __construct(
        private S3Client $client,
        private string $bucket,
        private string $prefix,
        private int $partSize,
        private ?string $storageClass = null,
    ) {}

    public function put(string $path, string $body): void
    {
        $this->call("write {$path}", fn () => $this->client->putObject($this->writeParams($path) + [
            'Body' => $body,
            'ContentMD5' => base64_encode(md5($body, true)),
        ]));
    }

    public function upload(string $path, iterable $chunks, string $contentType = 'application/octet-stream'): int
    {
        $uploadId = null;
        $parts = [];
        $total = 0;
        [$part, $md5, $buffered] = $this->newPart();

        try {
            foreach ($chunks as $chunk) {
                $length = mb_strlen($chunk, '8bit');

                if ($length === 0) {
                    continue;
                }

                fwrite($part, $chunk);
                hash_update($md5, $chunk);
                $buffered += $length;
                $total += $length;

                // Parts grow every 1000 so a stream of any size stays under the 10000 part limit.
                if ($buffered >= $this->partSize << min(9, intdiv(count($parts), 1000))) {
                    $uploadId ??= $this->call("start the upload of {$path}", fn () => $this->client->createMultipartUpload($this->writeParams($path) + ['ContentType' => $contentType]))['UploadId'];
                    $parts[] = $this->uploadPart($path, $uploadId, count($parts) + 1, $part, $md5, $buffered);
                    [$part, $md5, $buffered] = $this->newPart();
                }
            }

            if ($uploadId === null) {
                rewind($part);
                $this->call("write {$path}", fn () => $this->client->putObject($this->writeParams($path) + [
                    'Body' => $part,
                    'ContentLength' => $buffered,
                    'ContentType' => $contentType,
                    'ContentMD5' => base64_encode(hash_final($md5, true)),
                ]));

                return $total;
            }

            if ($buffered > 0) {
                $parts[] = $this->uploadPart($path, $uploadId, count($parts) + 1, $part, $md5, $buffered);
            }

            if (count($parts) > self::MAX_PARTS) {
                throw new StoreException("{$path} needs more than ".self::MAX_PARTS.' parts; raise the upload part size.');
            }

            $this->call("finish the upload of {$path}", fn () => $this->client->completeMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $this->key($path),
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => $parts],
            ]));

            return $total;
        } catch (Throwable $exception) {
            if ($uploadId !== null) {
                try {
                    $this->client->abortMultipartUpload(['Bucket' => $this->bucket, 'Key' => $this->key($path), 'UploadId' => $uploadId]);
                } catch (Throwable) {
                }
            }

            throw $this->failure($exception, "upload {$path}");
        } finally {
            if (is_resource($part)) {
                fclose($part);
            }
        }
    }

    public function read(string $path): Generator
    {
        $result = $this->call("read {$path}", fn () => $this->client->getObject($this->params($path) + ['@http' => ['stream' => true]]));
        $expected = (int) $result['ContentLength'];
        $body = $result['Body'];
        $buffer = '';
        $read = 0;

        try {
            while (! $body->eof()) {
                $data = $body->read(self::PIECE);
                $read += mb_strlen($data, '8bit');
                $buffer .= $data;

                if (mb_strlen($buffer, '8bit') >= self::PIECE) {
                    yield $buffer;
                    $buffer = '';
                }
            }
        } catch (Throwable $exception) {
            throw $this->failure($exception, "read {$path}");
        } finally {
            $body->close();
        }

        if ($read !== $expected) {
            throw new StoreException("Reading {$path} stopped after {$read} of {$expected} bytes.");
        }

        if ($buffer !== '') {
            yield $buffer;
        }
    }

    public function readRange(string $path, int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $data = $this->call("read {$path}", fn () => (string) $this->client->getObject($this->params($path) + [
            'Range' => 'bytes='.$offset.'-'.($offset + $length - 1),
        ])['Body']);

        if (mb_strlen($data, '8bit') !== $length) {
            throw new StoreException("{$path} is shorter than expected.");
        }

        return $data;
    }

    public function size(string $path): ?int
    {
        try {
            return (int) $this->client->headObject($this->params($path))['ContentLength'];
        } catch (AwsException $exception) {
            if ($exception->getStatusCode() === 404) {
                return null;
            }

            throw $this->failure($exception, "check {$path}");
        } catch (Throwable $exception) {
            throw $this->failure($exception, "check {$path}");
        }
    }

    public function delete(string $path): void
    {
        $this->call("delete {$path}", fn () => $this->client->deleteObject($this->params($path)));
    }

    public function deleteMany(array $paths): void
    {
        foreach (array_chunk(array_values($paths), 1000) as $batch) {
            $result = $this->call('delete objects', fn () => $this->client->deleteObjects([
                'Bucket' => $this->bucket,
                'Delete' => ['Objects' => array_map(fn (string $path): array => ['Key' => $this->key($path)], $batch), 'Quiet' => true],
            ]));

            $errors = array_filter((array) ($result['Errors'] ?? []), static fn (array $error): bool => ($error['Code'] ?? '') !== 'NoSuchKey');

            if ($errors !== []) {
                $error = reset($errors);

                throw new StoreException('Could not delete '.$error['Key'].': '.($error['Message'] ?? $error['Code'] ?? 'unknown error'));
            }
        }
    }

    public function list(string $prefix): Generator
    {
        $prefix = mb_trim($prefix, '/');
        $base = $this->prefix === '' ? '' : $this->prefix.'/';
        $pages = $this->client->getPaginator('ListObjectsV2', [
            'Bucket' => $this->bucket,
            'Prefix' => $base.($prefix === '' ? '' : $prefix.'/'),
        ]);

        try {
            foreach ($pages as $page) {
                foreach ($page['Contents'] ?? [] as $object) {
                    yield [
                        'path' => mb_substr($object['Key'], mb_strlen($base, '8bit'), null, '8bit'),
                        'size' => (int) $object['Size'],
                        'modified' => $object['LastModified']->getTimestamp(),
                    ];
                }
            }
        } catch (Throwable $exception) {
            throw $this->failure($exception, 'list '.($prefix === '' ? 'the bucket' : $prefix));
        }
    }

    public function presignedGet(string $path, int $seconds, string $filename): ?string
    {
        $filename = str_replace(['"', '\\', "\r", "\n"], '', $filename);
        $command = $this->client->getCommand('GetObject', $this->params($path) + [
            'ResponseContentType' => 'application/x-gzip',
            'ResponseContentDisposition' => "attachment; filename=\"{$filename}\"",
        ]);

        return (string) $this->client->createPresignedRequest($command, "+{$seconds} seconds")->getUri();
    }

    public function probe(): void
    {
        $path = '.offsite-backups-probe-'.bin2hex(random_bytes(6));
        $body = random_bytes(64);
        $this->put($path, $body);

        try {
            if ($this->readRange($path, 0, 64) !== $body) {
                throw new StoreException('The test object read back differs from what was written.');
            }
        } finally {
            $this->delete($path);
        }
    }

    private static function network(Throwable $exception): ?string
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            $message = $cause->getMessage();

            $reason = match (true) {
                str_contains($message, 'cURL error 6:'), str_contains($message, 'Could not resolve host') => 'the host name could not be found (DNS).',
                str_contains($message, 'cURL error 7:') => 'the server refused the connection or cannot be reached.',
                str_contains($message, 'cURL error 28:') => 'the connection timed out.',
                (bool) preg_match('/cURL error (35|51|58|59|60|77|83|90|91):/', $message), str_contains($message, 'SSL') => 'the TLS connection failed or the certificate is not trusted.',
                default => null,
            };

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    private function key(string $path): string
    {
        $path = mb_ltrim($path, '/');

        return $this->prefix === '' ? $path : $this->prefix.'/'.$path;
    }

    /** @return array{Bucket: string, Key: string} */
    private function params(string $path): array
    {
        return ['Bucket' => $this->bucket, 'Key' => $this->key($path)];
    }

    /** @return array<string, string> */
    private function writeParams(string $path): array
    {
        return $this->params($path) + ($this->storageClass !== null && $this->storageClass !== '' ? ['StorageClass' => $this->storageClass] : []);
    }

    /** @return array{0: resource, 1: HashContext, 2: int} */
    private function newPart(): array
    {
        $part = fopen('php://temp/maxmemory:'.(2 * self::PIECE), 'w+b');

        if ($part === false) {
            throw new StoreException('Could not open a temporary buffer.');
        }

        return [$part, hash_init('md5'), 0];
    }

    /**
     * @param  resource  $part
     * @return array{PartNumber: int, ETag: string}
     */
    private function uploadPart(string $path, string $uploadId, int $number, $part, HashContext $md5, int $length): array
    {
        rewind($part);

        // The SDK closes the stream once it is sent.
        $result = $this->call("upload part {$number} of {$path}", fn () => $this->client->uploadPart([
            'Bucket' => $this->bucket,
            'Key' => $this->key($path),
            'UploadId' => $uploadId,
            'PartNumber' => $number,
            'Body' => $part,
            'ContentLength' => $length,
            'ContentMD5' => base64_encode(hash_final($md5, true)),
        ]));

        return ['PartNumber' => $number, 'ETag' => (string) $result['ETag']];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $request
     * @return T
     */
    private function call(string $action, callable $request): mixed
    {
        try {
            return $request();
        } catch (Throwable $exception) {
            throw $this->failure($exception, $action);
        }
    }

    private function failure(Throwable $exception, string $action): StoreException
    {
        if ($exception instanceof StoreException) {
            return $exception;
        }

        return new StoreException("Could not {$action}: ".$this->reason($exception), 0, $exception);
    }

    private function reason(Throwable $exception): string
    {
        $network = self::network($exception);

        if ($network !== null) {
            return $network;
        }

        if (! $exception instanceof AwsException) {
            return $exception->getMessage();
        }

        return match ($exception->getAwsErrorCode()) {
            'InvalidAccessKeyId' => 'the access key is not known to the S3 server.',
            'SignatureDoesNotMatch' => 'the secret key does not match the access key.',
            'NoSuchBucket' => "the bucket \"{$this->bucket}\" does not exist.",
            'AccessDenied', 'AllAccessDisabled', 'Forbidden' => 'access denied. The key needs permission to read, write, list and delete objects in this bucket.',
            'AuthorizationHeaderMalformed', 'PermanentRedirect', 'IllegalLocationConstraintException' => 'the bucket is in another region. Check the region.',
            'RequestTimeTooSkewed' => 'the panel clock differs too much from the S3 server.',
            'NoSuchKey', 'NotFound' => 'the object does not exist.',
            'NoSuchUpload' => 'the upload was cancelled by the S3 server.',
            'InvalidStorageClass' => 'the storage class is not supported by this S3 server.',
            default => match ($exception->getStatusCode()) {
                403 => 'access denied. The key needs permission to read, write, list and delete objects in this bucket.',
                404 => 'not found. Check the bucket name and endpoint.',
                default => mb_trim(($exception->getAwsErrorCode() ?? 'error').' '.($exception->getAwsErrorMessage() ?: $exception->getMessage())),
            },
        };
    }
}
