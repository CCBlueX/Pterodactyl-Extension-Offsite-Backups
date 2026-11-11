<?php

declare(strict_types=1);

namespace OffsiteBackups\Transfers;

use OffsiteBackups\Crypto\Keys;
use OffsiteBackups\Crypto\Vault;
use OffsiteBackups\Models\Destination;
use OffsiteBackups\Storage\Store;
use OffsiteBackups\Storage\Stores;

/**
 * Opens a destination: its store and, for an encrypted one, the keys from its vault file.
 */
final readonly class Connector
{
    public function __construct(private Stores $stores, private Vault $vault) {}

    /** @return array{0: Store, 1: Keys|null} */
    public function open(Destination $destination): array
    {
        $store = $this->stores->for($destination);

        return [$store, $this->vault->open($store, $destination->encrypted ? $destination->passphrase : null)];
    }
}
