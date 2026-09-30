<?php

declare(strict_types=1);

namespace Kaly\Http\Cookie;

use InvalidArgumentException;

/**
 * The application-scoped baseline every cookie (application cookies and the
 * session cookie) is built from.
 *
 * Immutable and boring on purpose: it only carries values, never reads the
 * request, the response, the container or PHP globals. A null entry means
 * "inherit from php.ini at the use site"; an explicit entry wins over it.
 * Cookie *values* stay request-scoped (Cookies, SessionInterface).
 *
 * There is no global default: one instance lives in the container, bound per
 * App with the historical baseline (session cookie dies with the browser,
 * httponly, Lax) unless the application overrides it:
 *
 * ```text
 * application config
 *       ↓
 * CookiePolicy (container service, one per App)
 *       ↓
 *  ┌────┴────┐
 * Cookies  SessionProvider
 * ```
 */
final class CookiePolicy
{
    public const SAMESITE_MODES = ['None', 'Lax', 'Strict'];

    /**
     * @param 'None'|'Lax'|'Strict'|'none'|'lax'|'strict'|null $sameSite Canonicalised on the way in, so a
     *        policy can never hold a mode the Set-Cookie builder would drop
     */
    public function __construct(
        public readonly ?int $lifetime = null,
        public readonly ?string $path = null,
        public readonly ?string $domain = null,
        public readonly ?bool $secure = null,
        public readonly ?bool $httpOnly = null,
        ?string $sameSite = null,
        public readonly ?bool $partitioned = null,
    ) {
        $canonical = $sameSite === null ? null : ucfirst(strtolower($sameSite));
        if ($canonical !== null && !in_array($canonical, self::SAMESITE_MODES, true)) {
            throw new InvalidArgumentException("Invalid SameSite mode '{$sameSite}', expected None, Lax or Strict");
        }
        $this->sameSite = $canonical;
    }

    public readonly ?string $sameSite;

    /**
     * The historical kaly baseline: the session cookie dies with the browser,
     * httponly, Lax. Everything else inherits php.ini until configured.
     */
    public static function baseline(): self
    {
        return new self(lifetime: 0, httpOnly: true, sameSite: 'Lax');
    }

    /**
     * Derive a policy. Null means "keep the current value": to clear an
     * override back to php.ini inheritance, start from a fresh baseline instead.
     *
     * @param 'None'|'Lax'|'Strict'|'none'|'lax'|'strict'|null $sameSite
     */
    public function with(
        ?int $lifetime = null,
        ?string $path = null,
        ?string $domain = null,
        ?bool $secure = null,
        ?bool $httpOnly = null,
        ?string $sameSite = null,
        ?bool $partitioned = null,
    ): self {
        return new self(
            lifetime: $lifetime ?? $this->lifetime,
            path: $path ?? $this->path,
            domain: $domain ?? $this->domain,
            secure: $secure ?? $this->secure,
            httpOnly: $httpOnly ?? $this->httpOnly,
            sameSite: $sameSite ?? $this->sameSite,
            partitioned: $partitioned ?? $this->partitioned,
        );
    }

    /**
     * Only the explicit entries, shaped for SetCookieHeader/CookieParams.
     *
     * @return array{lifetime?:int,path?:string,domain?:string,secure?:bool,httponly?:bool,samesite?:string,partitioned?:bool}
     */
    public function toArray(): array
    {
        $params = [];
        if ($this->lifetime !== null) {
            $params['lifetime'] = $this->lifetime;
        }
        if ($this->path !== null) {
            $params['path'] = $this->path;
        }
        if ($this->domain !== null) {
            $params['domain'] = $this->domain;
        }
        if ($this->secure !== null) {
            $params['secure'] = $this->secure;
        }
        if ($this->httpOnly !== null) {
            $params['httponly'] = $this->httpOnly;
        }
        if ($this->sameSite !== null) {
            $params['samesite'] = $this->sameSite;
        }
        if ($this->partitioned !== null) {
            $params['partitioned'] = $this->partitioned;
        }
        return $params;
    }
}
