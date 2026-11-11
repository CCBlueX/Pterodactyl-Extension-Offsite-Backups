<?php

declare(strict_types=1);

namespace OffsiteBackups\Crypto;

use OffsiteBackups\Storage\StoreException;

final class CryptoException extends StoreException
{
    public static function damaged(): self
    {
        return new self('Decryption failed: the passphrase is wrong or the data is damaged.');
    }
}
