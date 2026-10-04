<?php

declare(strict_types=1);

namespace Kaly\Http\Csp;

use Kaly\Util\Base64Url;

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
        return $this->nonce ??= Base64Url::encode(random_bytes(18));
    }
}
