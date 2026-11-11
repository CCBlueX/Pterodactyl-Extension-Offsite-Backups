<?php

declare(strict_types=1);

namespace OffsiteBackups\Crypto;

use OffsiteBackups\Formats\Paths;
use OffsiteBackups\Storage\Store;
use OffsiteBackups\Storage\StoreException;
use SensitiveParameter;

/** The key file at the destination root that pins whether, and with which passphrase, it is encrypted. */
final readonly class Vault
{
    private const string CHECK = 'offsite-backups';

    /** @return Keys|null Null for an unencrypted destination, or one without a key file yet when $create is false. */
    public static function open(Store $store, #[SensitiveParameter] ?string $passphrase, bool $create = true): ?Keys
    {
        $passphrase = $passphrase === '' ? null : $passphrase;
        $vault = self::load($store);

        if ($vault === null) {
            if (! $create) {
                return null;
            }

            $store->put(Paths::VAULT, self::create($passphrase));
            // Another worker may have created it at the same time; the stored file wins.
            $vault = self::load($store) ?? throw new StoreException('The key file could not be read back from the destination.');
        }

        if (! $vault['encrypted']) {
            if ($passphrase !== null) {
                throw new StoreException('This destination already holds unencrypted copies. Remove the passphrase or use another folder or bucket.');
            }

            return null;
        }

        if ($passphrase === null) {
            throw new StoreException('This destination holds encrypted copies. Enter its passphrase.');
        }

        $keys = Keys::derive($passphrase, $vault['salt'], $vault['ops'], $vault['mem']);

        try {
            $check = $keys->open($vault['check']);
        } catch (CryptoException) {
            $check = null;
        }

        if ($check !== self::CHECK) {
            throw new StoreException('The passphrase does not match the one this destination was encrypted with.');
        }

        return $keys;
    }

    private static function create(?string $passphrase): string
    {
        if ($passphrase === null) {
            return json_encode(['version' => 1, 'encrypted' => false], JSON_THROW_ON_ERROR);
        }

        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $ops = SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE;
        $mem = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;
        $keys = Keys::derive($passphrase, $salt, $ops, $mem);

        return json_encode([
            'version' => 1,
            'encrypted' => true,
            'salt' => base64_encode($salt),
            'ops' => $ops,
            'mem' => $mem,
            'check' => base64_encode($keys->seal(self::CHECK)),
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array{encrypted: bool, salt: string, ops: int, mem: int, check: string}|null */
    private static function load(Store $store): ?array
    {
        $size = $store->size(Paths::VAULT);

        if ($size === null) {
            return null;
        }

        $data = json_decode($size > 0 && $size < 65536 ? $store->readRange(Paths::VAULT, 0, $size) : '', true);

        if (! is_array($data) || ($data['version'] ?? null) !== 1 || ! is_bool($data['encrypted'] ?? null)) {
            throw new StoreException('The key file '.Paths::VAULT.' on this destination is damaged.');
        }

        if (! $data['encrypted']) {
            return ['encrypted' => false, 'salt' => '', 'ops' => 0, 'mem' => 0, 'check' => ''];
        }

        $salt = base64_decode((string) ($data['salt'] ?? ''), true);
        $check = base64_decode((string) ($data['check'] ?? ''), true);
        $ops = (int) ($data['ops'] ?? 0);
        $mem = (int) ($data['mem'] ?? 0);

        // Bounds keep a tampered file from making key derivation hang or exhaust memory.
        if ($salt === false || mb_strlen($salt, '8bit') !== SODIUM_CRYPTO_PWHASH_SALTBYTES || $check === false
            || $ops < 1 || $ops > SODIUM_CRYPTO_PWHASH_OPSLIMIT_SENSITIVE
            || $mem < 8192 || $mem > SODIUM_CRYPTO_PWHASH_MEMLIMIT_SENSITIVE) {
            throw new StoreException('The key file '.Paths::VAULT.' on this destination is damaged.');
        }

        return ['encrypted' => true, 'salt' => $salt, 'ops' => $ops, 'mem' => $mem, 'check' => $check];
    }
}
