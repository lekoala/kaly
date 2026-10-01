<?php

declare(strict_types=1);

namespace Kaly\Http\Session;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * How the session of a request is obtained and persisted.
 *
 * The provider owns HTTP and persistence: it reads the request to build the
 * session, then writes the session back to the final response, storage and
 * transport together. The session itself only carries applicative state and
 * never sees PSR-7.
 *
 * The commit happens once, after the outgoing phase: a session read or written
 * by an outgoing middleware is persisted like any other, and a response an
 * outgoing middleware replaced still carries the session cookie.
 *
 * ```text
 * HttpContext
 *     ↓
 * SessionProvider
 *     ├── create(request) → SessionInterface
 *     └── commit(session, request, response)   storage + transport
 * ```
 */
interface SessionProviderInterface
{
    public function create(ServerRequestInterface $request): SessionInterface;

    /**
     * Write the session storage back and apply its transport (the Set-Cookie
     * header) to the final response. A backend without a cookie returns the
     * response unchanged.
     */
    public function commit(SessionInterface $session, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface;
}
