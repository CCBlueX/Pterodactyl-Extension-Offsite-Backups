<?php

declare(strict_types=1);

namespace OffsiteBackups\Crypto;

use SensitiveParameter;

/** Keys derived from a destination passphrase. */
final readonly class Keys
{
    private const string CONTEXT = 'ofsbkup1';

    private function __construct(
        #[SensitiveParameter] private string $sealKey,
        #[SensitiveParameter] private string $streamKey,
        #[SensitiveParameter] private string $idKey,
    ) {}

    public static function derive(#[SensitiveParameter] string $passphrase, string $salt, int $ops, int $mem): self
    {
        $master = sodium_crypto_pwhash(SODIUM_CRYPTO_KDF_KEYBYTES, $passphrase, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);

        $keys = new self(
            sodium_crypto_kdf_derive_from_key(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 1, self::CONTEXT, $master),
            sodium_crypto_kdf_derive_from_key(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES, 2, self::CONTEXT, $master),
            sodium_crypto_kdf_derive_from_key(32, 3, self::CONTEXT, $master),
        );

        sodium_memzero($master);

        return $keys;
    }

    /** Encrypts with a random nonce in front. */
    public function seal(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return $nonce.sodium_crypto_secretbox($plain, $nonce, $this->sealKey);
    }

    public function open(string $sealed): string
    {
        if (mb_strlen($sealed, '8bit') < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw CryptoException::damaged();
        }

        $plain = sodium_crypto_secretbox_open(
            mb_substr($sealed, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, null, '8bit'),
            mb_substr($sealed, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, '8bit'),
            $this->sealKey,
        );

        return $plain !== false ? $plain : throw CryptoException::damaged();
    }

    /** @return string 32 raw bytes that name the data without revealing it. */
    public function id(string $data): string
    {
        return hash_hmac('sha256', $data, $this->idKey, true);
    }

    public function streamKey(): string
    {
        return $this->streamKey;
    }
}
