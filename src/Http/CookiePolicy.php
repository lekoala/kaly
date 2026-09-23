<?php

declare(strict_types=1);

namespace Kaly\Http;

/**
 * The application-scoped baseline every cookie (application cookies and the
 * session cookie) is built from.
 *
 * Immutable and boring on purpose: it only carries values, never reads the
 * request, the response, the container or PHP globals. A null entry means
 * "inherit from php.ini at the use site"; an explicit entry wins over it.
 * Cookie *values* stay request-scoped (Cookies, SessionInterface).
 *
 * The default is set once at boot and shared by Cookies and sessions unless
 * an instance is injected explicitly:
 *
 * ```text
 * application config
 *       ↓
 * CookiePolicy
 *       ↓
 *  ┌────┴────┐
 * Cookies  Session
 * ```
 */
final class CookiePolicy
{
    public function __construct(
        public readonly ?int $lifetime = null,
        public readonly ?string $path = null,
        public readonly ?string $domain = null,
        public readonly ?bool $secure = null,
        public readonly ?bool $httpOnly = null,
        public readonly ?string $sameSite = null,
        public readonly ?bool $partitioned = null,
    ) {}

    private static ?self $default = null;

    /**
     * The application baseline. Seeded with the historical kaly defaults
     * (session cookie dies with the browser, httponly, Lax); everything else
     * inherits php.ini until configured.
     */
    public static function default(): self
    {
        return self::$default ??= new self(lifetime: 0, httpOnly: true, sameSite: 'Lax');
    }

    /**
     * Set once at boot. Prefer injecting a policy per instance in tests.
     */
    public static function setDefault(self $policy): void
    {
        self::$default = $policy;
    }

    /**
     * Derive a policy. Null means "keep the current value": to clear an
     * override back to php.ini inheritance, set a fresh default instead.
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
