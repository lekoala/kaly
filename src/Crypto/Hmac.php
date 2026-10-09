<?php

declare(strict_types=1);

namespace Kaly\Crypto;

use Kaly\Util\Base64Url;
use LogicException;
use SensitiveParameter;

/**
 * HMAC-SHA256 signatures for one purpose of the application secret.
 *
 * The key is derived from the secret and the purpose, so a signature made
 * for `form-protection:v1` never verifies as an `auth-verification:v1` one.
 * Signatures are base64url text (43 characters) and verification runs in
 * constant time. Like the secret, the derived key is kept out of the
 * instance properties, so dumps only show the purpose.
 *
 * A signature only proves who produced a message. Expiry, attempt limits and
 * single use stay the caller's rules, and the message must bind every piece
 * of context that matters: encode several fields unambiguously, eg with
 * `json_encode([$memberId, $recipient, $code])`, never by concatenation.
 */
final class Hmac
{
    /**
     * Derived keys by instance, out of reach of dumpers.
     *
     * @var \WeakMap<self,string>|null
     */
    private static ?\WeakMap $keys = null;

    public function __construct(
        #[SensitiveParameter]
        Secret $secret,
        public readonly string $purpose,
    ) {
        // The prefix separates HMAC keys from other keys derived for the same purpose.
        self::$keys ??= new \WeakMap();
        self::$keys[$this] = $secret->deriveKey('kaly.hmac:' . $purpose);
    }

    public function sign(#[SensitiveParameter] string $message): string
    {
        return Base64Url::encode(hash_hmac('sha256', $message, self::key($this), true));
    }

    public function verify(#[SensitiveParameter] string $message, string $signature): bool
    {
        return hash_equals($this->sign($message), $signature);
    }

    /**
     * @throws LogicException Always: an instance owns its key material
     */
    public function __clone(): void
    {
        throw new LogicException('A signing key cannot be cloned');
    }

    /**
     * @return array<mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A signing key cannot be serialized');
    }

    /**
     * @param array<mixed> $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('A signing key cannot be unserialized');
    }

    private static function key(self $hmac): string
    {
        return self::$keys[$hmac] ?? throw new LogicException('A signing key must be constructed before use');
    }
}
