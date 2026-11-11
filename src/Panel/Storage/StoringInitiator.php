<?php

declare(strict_types=1);

namespace OffsiteBackups\Panel\Storage;

use OffsiteBackups\Models\Destination;
use Pterodactyl\Contracts\Backups\InitiatesBackups;
use Pterodactyl\Models\Backup;
use Pterodactyl\Models\Server;

/**
 * Starts a backup on the disk chosen for the server and remembers which destination Wings uploads it to.
 */
final readonly class StoringInitiator implements InitiatesBackups
{
    public function __construct(private InitiatesBackups $inner) {}

    public function setIsLocked(bool $isLocked): InitiatesBackups
    {
        $this->inner->setIsLocked($isLocked);

        return $this;
    }

    public function setIgnoredFiles(?array $ignored): InitiatesBackups
    {
        $this->inner->setIgnoredFiles($ignored);

        return $this;
    }

    public function initiate(Server $server, ?string $name = null, bool $override = false): Backup
    {
        $disks = app(Disks::class);
        $choice = $disks->choiceFor($server);

        if ($choice === null) {
            return $this->inner->initiate($server, $name, $override);
        }

        $destination = $choice instanceof Destination ? $choice : null;
        $backup = $disks->with($destination === null ? Disks::NODE : Backup::ADAPTER_AWS_S3, $destination, fn (): Backup => $this->inner->initiate($server, $name, $override));

        if ($destination !== null && $backup->disk === Backup::ADAPTER_AWS_S3) {
            $disks->remember($backup, $destination);
        }

        return $backup;
    }
}
