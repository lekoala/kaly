<?php

declare(strict_types=1);

namespace Kaly\Http\Session;

use Kaly\Http\Cookie\CookiePolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The default session provider: native PHP sessions, one instance per request.
 *
 * Sequential execution only: PHP's native session state is global to the
 * process, so concurrent runtimes must bind a provider returning a
 * request-scoped SessionInterface implementation instead.
 *
 * The provider owns transport: it derives the session id from the request
 * cookie, scopes the cookie to the request (secure/domain), commits the
 * storage and applies the Set-Cookie header to the final response.
 *
 * The constructor is pure: it touches no global PHP state (no ini, no
 * output). Everything session_start() needs (no cookies, no url rewriting,
 * strict mode, no cache limiter) travels in the start options of each
 * NativePhpSession, so building the DI graph can never break a boot that
 * follows earlier output.
 */
final class NativePhpSessionProvider implements SessionProviderInterface
{
    private CookiePolicy $policy;
    /**
     * @var array<string,mixed>
     */
    private array $options;

    /**
     * @param array<string,mixed> $options Passed to NativePhpSession: 'name',
     *  'save_path', cookie overrides (lifetime, path, domain, secure,
     *  httponly, samesite, partitioned).
     */
    public function __construct(array $options = [], ?CookiePolicy $policy = null)
    {
        $this->options = $options;
        $this->policy = $policy ?? CookiePolicy::baseline();
    }

    public function create(ServerRequestInterface $request): SessionInterface
    {
        $options = SessionCookie::deriveOptions($this->options, $this->policy, $request);
        $session = new NativePhpSession($options, $this->policy);
        $id = SessionCookie::idFromRequest($request, $session->getName());
        if ($id !== null) {
            $session->setId($id);
        }
        return $session;
    }

    public function commit(SessionInterface $session, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($session instanceof CookieSessionInterface) {
            return SessionCookie::commit($session, $request, $response);
        }
        return $response;
    }
}
