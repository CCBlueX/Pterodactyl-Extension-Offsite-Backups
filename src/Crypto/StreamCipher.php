<?php

declare(strict_types=1);

namespace OffsiteBackups\Crypto;

use Generator;

/** Encrypts a stream in authenticated 1 MiB frames; the last frame is tagged, so truncation is detected. */
final readonly class StreamCipher
{
    public const int FRAME = 1024 * 1024;

    /**
     * @param  iterable<string>  $chunks
     * @return Generator<int, string>
     */
    public static function encrypt(iterable $chunks, Keys $keys): Generator
    {
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($keys->streamKey());

        yield $header;

        $buffer = '';

        foreach ($chunks as $chunk) {
            $buffer .= $chunk;

            if (mb_strlen($buffer, '8bit') <= self::FRAME) {
                continue;
            }

            $offset = 0;
            $length = mb_strlen($buffer, '8bit');

            // Keep at least one byte back so the last frame can carry the final tag.
            while ($length - $offset > self::FRAME) {
                yield sodium_crypto_secretstream_xchacha20poly1305_push($state, mb_substr($buffer, $offset, self::FRAME, '8bit'));
                $offset += self::FRAME;
            }

            $buffer = mb_substr($buffer, $offset, null, '8bit');
        }

        yield sodium_crypto_secretstream_xchacha20poly1305_push($state, $buffer, '', SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL);
    }

    /**
     * @param  iterable<string>  $chunks
     * @return Generator<int, string>
     */
    public static function decrypt(iterable $chunks, Keys $keys): Generator
    {
        $frame = self::FRAME + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        $headerBytes = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;
        $state = null;
        $buffer = '';

        foreach ($chunks as $chunk) {
            $buffer .= $chunk;

            if ($state === null) {
                if (mb_strlen($buffer, '8bit') < $headerBytes) {
                    continue;
                }

                $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull(mb_substr($buffer, 0, $headerBytes, '8bit'), $keys->streamKey());
                $buffer = mb_substr($buffer, $headerBytes, null, '8bit');
            }

            $offset = 0;
            $length = mb_strlen($buffer, '8bit');

            while ($length - $offset > $frame) {
                [$plain, $tag] = self::pull($state, mb_substr($buffer, $offset, $frame, '8bit'));

                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    throw new CryptoException('The encrypted data continues after its end.');
                }

                $offset += $frame;

                yield $plain;
            }

            if ($offset > 0) {
                $buffer = mb_substr($buffer, $offset, null, '8bit');
            }
        }

        if ($state === null || $buffer === '') {
            throw new CryptoException('The encrypted data is cut short.');
        }

        [$plain, $tag] = self::pull($state, $buffer);

        if ($tag !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
            throw new CryptoException('The encrypted data is cut short.');
        }

        if ($plain !== '') {
            yield $plain;
        }
    }

    /** @return array{0: string, 1: int} */
    private static function pull(string &$state, string $frame): array
    {
        $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $frame);

        return $result !== false ? $result : throw CryptoException::damaged();
    }
}
