<?php

declare(strict_types=1);

namespace Kaly\Crypto;

use InvalidArgumentException;
use Kaly\Util\Base64Url;
use LogicException;
use SensitiveParameter;

/**
 * The application root secret, the input of every derived key.
 *
 * Decoded and validated once. The raw bytes never leave the object: callers
 * get independent keys per purpose through HKDF-SHA256, so a key used for
 * one purpose reveals nothing about another. The bytes live outside the
 * instance properties, so no dumper (var_dump, print_r, Symfony VarDumper)
 * can see them; serialization and cloning are refused.
 *
 * Kaly never generates a missing secret: create one with `generate()` and
 * configure it explicitly (`APP_SECRET`).
 */
final class Secret
{
    public const MIN_BYTES = 32;

    /**
     * Dumpers read every instance property, private ones included, whatever
     * __debugInfo() returns: the bytes are keyed by instance instead.
     *
     * @var \WeakMap<self,string>|null
     */
    private static ?\WeakMap $bytes = null;

    public function __construct(#[SensitiveParameter] string $bytes)
    {
        if (strlen($bytes) < self::MIN_BYTES) {
            throw new InvalidArgumentException('A secret must contain at least ' . self::MIN_BYTES . ' bytes');
        }
        self::$bytes ??= new \WeakMap();
        self::$bytes[$this] = $bytes;
    }

    /**
     * Read a secret stored as base64url text, as produced by `generate()`.
     */
    public static function fromBase64Url(#[SensitiveParameter] string $encoded): self
    {
        $bytes = Base64Url::decode(trim($encoded));
        if ($bytes === null) {
            throw new InvalidArgumentException('A secret must be base64url encoded');
        }
        return new self($bytes);
    }

    /**
     * A new random secret as base64url text, ready to be configured.
     */
    public static function generate(): string
    {
        return Base64Url::encode(random_bytes(self::MIN_BYTES));
    }

    /**
     * Derive a key for one purpose. Distinct purposes give independent keys;
     * version the purpose (`auth-verification:v1`) to change a key on purpose.
     *
     * @return string Raw key bytes
     */
    public function deriveKey(string $purpose, int $length = 32): string
    {
        if ($purpose === '') {
            throw new InvalidArgumentException('A key purpose cannot be empty');
        }
        if ($length < 16 || $length > 64) {
            throw new InvalidArgumentException('A derived key must contain between 16 and 64 bytes');
        }
        return hash_hkdf('sha256', self::bytes($this), $length, $purpose);
    }

    /**
     * @return array{bytes:string}
     */
    public function __debugInfo(): array
    {
        return ['bytes' => '[redacted]'];
    }

    /**
     * @throws LogicException Always: an instance owns its key material
     */
    public function __clone(): void
    {
        throw new LogicException('A secret cannot be cloned');
    }

    /**
     * @return array<mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A secret cannot be serialized');
    }

    /**
     * @param array<mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A secret cannot be unserialized');
    }

    private static function bytes(#[SensitiveParameter] self $secret): string
    {
        return self::$bytes[$secret] ?? throw new LogicException('A secret must be constructed before use');
    }
}
