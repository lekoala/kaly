<?php

declare(strict_types=1);

namespace Kaly\Test;

use Kaly\Http\Cookie\CookiePolicy;
use Kaly\Http\Session\ArraySession;
use Kaly\Http\Session\CookieSessionInterface;
use Kaly\Http\Session\SessionCookie;
use Kaly\Http\Session\SessionInterface;
use Kaly\Http\Session\SessionProviderInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Test-only session storage: the data lives in the provider, the id travels
 * in the session cookie carried by the TestClient cookie store.
 *
 * Bind explicitly before booting the tested app; `ArraySessionProvider` stays
 * for isolated cycles and never persists silently. The memory belongs to the
 * provider: two clients driving one app stay isolated through their cookies.
 */
final class MemorySessionProvider implements SessionProviderInterface
{
    /**
     * @var array<string,array<string,mixed>> Session id => data
     */
    private array $store = [];

    /**
     * @param array<string,mixed> $options Passed to ArraySession ('name' plus
     *  cookie params). Explicit options win over the policy baseline.
     */
    public function __construct(
        private array $options = [],
        private ?CookiePolicy $policy = null,
    ) {}

    public function create(ServerRequestInterface $request): SessionInterface
    {
        $session = new ArraySession(
            SessionCookie::deriveOptions($this->options, $this->policy ?? CookiePolicy::baseline(), $request),
            $this->policy,
        );
        $id = SessionCookie::idFromRequest($request, $session->getName());
        if ($id !== null) {
            $session->setId($id);
            foreach ($this->store[$id] ?? [] as $key => $value) {
                $session->set($key, $value);
            }
        }
        return $session;
    }

    public function commit(SessionInterface $session, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($session instanceof ArraySession) {
            $incoming = $session->getName() !== '' ? SessionCookie::idFromRequest($request, $session->getName()) : null;
            if ($session->isDestroyed()) {
                if ($incoming !== null) {
                    unset($this->store[$incoming]);
                }
            } elseif ($session->isActive()) {
                $id = $session->getId();
                if ($id !== null) {
                    $this->store[$id] = $session->all();
                }
                if ($incoming !== null && $incoming !== $id) {
                    unset($this->store[$incoming]);
                }
            }
        }

        if ($session instanceof CookieSessionInterface) {
            return SessionCookie::commit($session, $request, $response);
        }
        return $response;
    }
}
