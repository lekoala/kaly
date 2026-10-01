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
 * cookie, scopes the cookie to the request (secure/domain), honors
 * remember-me, commits the storage and applies the Set-Cookie header to the
 * final response.
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
     *  httponly, samesite, partitioned) and behavior (regen_interval,
     *  expiry_key, remember_lifetime, remember_key).
     */
    public function __construct(array $options = [], ?CookiePolicy $policy = null)
    {
        $this->options = $options;
        $this->policy = $policy ?? CookiePolicy::baseline();
        NativePhpSession::configureForPsr7();
    }

    public function create(ServerRequestInterface $request): SessionInterface
    {
        $session = new NativePhpSession($this->optionsForRequest($request), $this->policy);
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

    /**
     * @return array<string,mixed>
     */
    private function optionsForRequest(ServerRequestInterface $request): array
    {
        $options = SessionCookie::deriveOptions($this->options, $this->policy, $request);
        if ($this->isRememberMe($request)) {
            $rememberLifetime = $options['remember_lifetime'] ?? 31_536_000;
            $options['lifetime'] = is_numeric($rememberLifetime) ? (int) $rememberLifetime : 31_536_000;
        }
        return $options;
    }

    private function isRememberMe(ServerRequestInterface $request): bool
    {
        if ($request->getMethod() !== 'POST') {
            return false;
        }
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return false;
        }
        $key = $this->options['remember_key'] ?? '_remember';
        if (!is_string($key) || $key === '') {
            $key = '_remember';
        }
        return !empty($body[$key]);
    }
}
