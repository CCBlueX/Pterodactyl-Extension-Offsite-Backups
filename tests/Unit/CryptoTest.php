<?php

declare(strict_types=1);

namespace OffsiteBackups\Tests\Unit;

use OffsiteBackups\Crypto\CryptoException;
use OffsiteBackups\Crypto\Keys;
use OffsiteBackups\Crypto\StreamCipher;
use OffsiteBackups\Crypto\Vault;
use OffsiteBackups\Formats\Paths;
use OffsiteBackups\Storage\MemoryStore;
use OffsiteBackups\Storage\StoreException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    private static ?Keys $keys = null;

    /** @return array<string, array{0: int}> */
    public static function lengths(): array
    {
        $frame = StreamCipher::FRAME;

        return ['empty' => [0], 'small' => [1000], 'one frame' => [$frame], 'frame and a byte' => [$frame + 1], 'three frames' => [3 * $frame]];
    }

    public static function keys(): Keys
    {
        return self::$keys ??= Keys::derive('test passphrase', str_repeat("\0", SODIUM_CRYPTO_PWHASH_SALTBYTES), 1, 8192);
    }

    /** @return list<string> */
    public static function pieces(string $data, int $size): array
    {
        $pieces = [];

        for ($offset = 0, $length = mb_strlen($data, '8bit'); $offset < $length; $offset += $size) {
            $pieces[] = mb_substr($data, $offset, $size, '8bit');
        }

        return $pieces;
    }

    public function test_seal_round_trips_and_detects_tampering(): void
    {
        $keys = self::keys();
        $sealed = $keys->seal('hello');

        $this->assertSame('hello', $keys->open($sealed));
        $this->assertNotSame($sealed, $keys->seal('hello'));

        $sealed[30] = $sealed[30] === 'a' ? 'b' : 'a';
        $this->expectException(CryptoException::class);
        $keys->open($sealed);
    }

    public function test_ids_depend_on_the_key(): void
    {
        $other = Keys::derive('another passphrase', str_repeat("\1", SODIUM_CRYPTO_PWHASH_SALTBYTES), 1, 8192);

        $this->assertSame(32, mb_strlen(self::keys()->id('data'), '8bit'));
        $this->assertSame(self::keys()->id('data'), self::keys()->id('data'));
        $this->assertNotSame(self::keys()->id('data'), $other->id('data'));
    }

    #[DataProvider('lengths')]
    public function test_stream_round_trips(int $length): void
    {
        $plain = random_bytes(max(1, $length));
        $plain = mb_substr($plain, 0, $length, '8bit');
        $cipher = implode('', iterator_to_array(StreamCipher::encrypt(self::pieces($plain, 70_000), self::keys()), false));

        $this->assertSame($plain, implode('', iterator_to_array(StreamCipher::decrypt(self::pieces($cipher, 123_457), self::keys()), false)));
    }

    public function test_truncated_stream_is_rejected(): void
    {
        $cipher = implode('', iterator_to_array(StreamCipher::encrypt([random_bytes(3 * StreamCipher::FRAME)], self::keys()), false));
        $frame = StreamCipher::FRAME + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        $header = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;

        foreach ([$header + 2 * $frame, $header + $frame + 10, $header] as $cut) {
            try {
                iterator_to_array(StreamCipher::decrypt([mb_substr($cipher, 0, $cut, '8bit')], self::keys()), false);
                $this->fail("A stream cut at {$cut} bytes was accepted.");
            } catch (CryptoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_stream_with_wrong_key_is_rejected(): void
    {
        $other = Keys::derive('wrong', str_repeat("\2", SODIUM_CRYPTO_PWHASH_SALTBYTES), 1, 8192);
        $cipher = iterator_to_array(StreamCipher::encrypt(['secret data'], self::keys()), false);

        $this->expectException(CryptoException::class);
        iterator_to_array(StreamCipher::decrypt($cipher, $other), false);
    }

    public function test_vault_pins_the_passphrase(): void
    {
        $store = new MemoryStore;
        $keys = Vault::open($store, 'correct horse');

        $this->assertNotNull($keys);
        $this->assertTrue(json_decode($store->objects[Paths::VAULT]['body'], true)['encrypted']);
        $this->assertSame($keys->id('x'), Vault::open($store, 'correct horse')?->id('x'));

        foreach (['wrong horse', null, ''] as $passphrase) {
            try {
                Vault::open($store, $passphrase);
                $this->fail('The vault opened with a wrong passphrase.');
            } catch (StoreException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_unencrypted_vault_refuses_a_passphrase(): void
    {
        $store = new MemoryStore;

        $this->assertNull(Vault::open($store, null));
        $this->assertNull(Vault::open($store, ''));
        $this->assertFalse(json_decode($store->objects[Paths::VAULT]['body'], true)['encrypted']);

        $this->expectException(StoreException::class);
        Vault::open($store, 'late passphrase');
    }
}
