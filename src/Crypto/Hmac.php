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
 * constant time.
 *
 * A signature only proves who produced a message. Expiry, attempt limits and
 * single use stay the caller's rules, and the message must bind every piece
 * of context that matters: encode several fields unambiguously, eg with
 * `json_encode([$memberId, $recipient, $code])`, never by concatenation.
 */
final readonly class Hmac
{
    private string $key;

    public function __construct(
        #[SensitiveParameter]
        Secret $secret,
        public string $purpose,
    ) {
        // The prefix separates HMAC keys from other keys derived for the same purpose.
        $this->key = $secret->deriveKey('kaly.hmac:' . $purpose);
    }

    public function sign(#[SensitiveParameter] string $message): string
    {
        return Base64Url::encode(hash_hmac('sha256', $message, $this->key, true));
    }

    public function verify(#[SensitiveParameter] string $message, string $signature): bool
    {
        return hash_equals($this->sign($message), $signature);
    }

    /**
     * @return array{purpose:string}
     */
    public function __debugInfo(): array
    {
        return ['purpose' => $this->purpose];
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
}
