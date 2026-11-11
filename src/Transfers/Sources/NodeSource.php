<?php

declare(strict_types=1);

namespace OffsiteBackups\Transfers\Sources;

use Generator;
use LogicException;
use OffsiteBackups\Panel\Panel;
use OffsiteBackups\Transfers\Permanent;
use Psr\Http\Message\StreamInterface;
use Pterodactyl\Models\Server;
use RuntimeException;

/**
 * A backup stored on the server's node, downloaded from Wings.
 */
final class NodeSource implements Source
{
    private const int CHUNK = 1024 * 1024;

    private ?StreamInterface $stream = null;

    public function __construct(private readonly Panel $panel, private readonly Server $server, private readonly string $backupUuid) {}

    public function open(): ?int
    {
        try {
            [$this->stream, $size] = $this->panel->backupStream($this->server, $this->backupUuid);
        } catch (RuntimeException $exception) {
            throw $exception->getCode() === 404 ? new Permanent('The backup is no longer on the node.', 404, $exception) : $exception;
        }

        return $size;
    }

    public function chunks(): Generator
    {
        $stream = $this->stream ?? throw new LogicException('The source is not open.');

        $buffer = '';

        try {
            // Socket reads return a few KiB at a time; hand the writers 1 MiB pieces.
            while (! $stream->eof()) {
                $buffer .= $stream->read(self::CHUNK);

                if (mb_strlen($buffer, '8bit') >= self::CHUNK) {
                    yield $buffer;
                    $buffer = '';
                }
            }

            if ($buffer !== '') {
                yield $buffer;
            }
        } finally {
            $stream->close();
            $this->stream = null;
        }
    }
}
