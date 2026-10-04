<?php

declare(strict_types=1);

namespace Kaly\Http\Csp;

/**
 * The Content Security Policy nonce of the current response.
 *
 * One request owns one nonce, generated lazily on first use and shared by
 * templates (explicit `nonce` attributes) and the outgoing CSP header. The
 * policy itself stays applicative: this only provides the unpredictable
 * value both sides must agree on.
 */
final class Csp
{
    private ?string $nonce = null;

    public function nonce(): string
    {
        return $this->nonce ??= self::base64Url(random_bytes(18));
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
