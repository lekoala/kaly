<?php

declare(strict_types=1);

namespace Kaly\Http\Session;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * How the session of a request is obtained and persisted.
 *
 * The provider owns HTTP and persistence: it reads the request to build the
 * session, persists it, then writes its transport back to the response. The
 * session itself only carries applicative state and never sees PSR-7.
 *
 * Persistence and transport are two distinct moments: `persist()` releases
 * the storage as soon as the request pipeline is done, while
 * `applyToResponse()` adds the session cookie to the final response, after
 * the outgoing phase. This way an outgoing failure that replaces the response
 * cannot lose a session, nor a cookie.
 *
 * ```text
 * HttpContext
 *     ↓
 * SessionProvider
 *     ├── create(request) → SessionInterface
 *     ├── persist(session)                        storage
 *     └── applyToResponse(session, request, response)   transport
 * ```
 */
interface SessionProviderInterface
{
    public function create(ServerRequestInterface $request): SessionInterface;

    /**
     * Write the session storage back. Never touches the response.
     */
    public function persist(SessionInterface $session): void;

    /**
     * Apply the session transport (its Set-Cookie header) to the final
     * response. A backend without a cookie returns the response unchanged.
     */
    public function applyToResponse(
        SessionInterface $session,
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface;
}
