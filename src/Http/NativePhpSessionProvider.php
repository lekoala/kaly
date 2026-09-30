<?php

declare(strict_types=1);

namespace Kaly\Http;

use InvalidArgumentException;
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
 * remember-me, and emits the Set-Cookie header on commit.
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
        $options = $this->optionsForRequest($request);
        $session = new NativePhpSession($options, $this->policy);

        $id = $this->idFromRequest($request, $session->getName());
        if ($id !== null) {
            $session->setId($id);
        }

        return $session;
    }

    public function commit(SessionInterface $session, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($session instanceof NativePhpSession) {
            return $session->commitToResponse($request, $response);
        }
        if ($session instanceof ArraySession) {
            return $session->commitToResponse($request, $response);
        }
        return $response;
    }

    /**
     * @return array<string,mixed>
     */
    private function optionsForRequest(ServerRequestInterface $request): array
    {
        $options = $this->options;
        // Scope the cookie to the request; explicit options still win.
        if (!array_key_exists('secure', $options)) {
            $options['secure'] = $request->getUri()->getScheme() === 'https';
        }
        if (!array_key_exists('domain', $options)) {
            $options['domain'] = $request->getUri()->getHost();
        }
        if ($this->isRememberMe($request)) {
            $rememberLifetime = $options['remember_lifetime'] ?? 31_536_000;
            $options['lifetime'] = is_numeric($rememberLifetime) ? (int) $rememberLifetime : 31_536_000;
        }
        return $options;
    }

    private function idFromRequest(ServerRequestInterface $request, string $name): ?string
    {
        $cookies = $request->getCookieParams();
        $param = $cookies[$name] ?? null;
        if ($param !== null && !is_string($param)) {
            throw new InvalidArgumentException('Session cookie value must be a string');
        }
        return $param;
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
