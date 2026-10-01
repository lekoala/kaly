<?php

declare(strict_types=1);

namespace Kaly\Http\Session;

use Kaly\Http\Cookie\CookiePolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Request-scoped in-memory sessions, one instance per request.
 *
 * Concurrency-safe by construction. For tests and isolated cycles: nothing
 * persists between two requests. This is not a production backend.
 */
final class ArraySessionProvider implements SessionProviderInterface
{
    private CookiePolicy $policy;
    /**
     * @var array<string,mixed>
     */
    private array $options;

    /**
     * @param array<string,mixed> $options Passed to ArraySession ('name' plus
     *  cookie params). Explicit options win over the policy baseline.
     */
    public function __construct(array $options = [], ?CookiePolicy $policy = null)
    {
        $this->options = $options;
        $this->policy = $policy ?? CookiePolicy::baseline();
    }

    public function create(ServerRequestInterface $request): SessionInterface
    {
        $session = new ArraySession(SessionCookie::deriveOptions($this->options, $this->policy, $request), $this->policy);
        $id = SessionCookie::idFromRequest($request, $session->getName());
        if ($id !== null) {
            $session->setId($id);
        }
        return $session;
    }

    public function persist(SessionInterface $session): void
    {
        if ($session instanceof CookieSessionInterface) {
            SessionCookie::release($session);
        }
    }

    public function applyToResponse(
        SessionInterface $session,
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        if ($session instanceof CookieSessionInterface) {
            return SessionCookie::apply($session, $request, $response);
        }
        return $response;
    }
}
