<?php

declare(strict_types=1);

namespace Kaly\Http\Session;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * How the session of a request is obtained and persisted.
 *
 * The provider owns HTTP and persistence: it reads the request to build the
 * session, and writes the session back to the response. The session itself
 * only carries applicative state and never sees PSR-7.
 *
 * ```text
 * HttpContext
 *     ↓
 * SessionProvider
 *     ├── create(request) → SessionInterface
 *     └── commit(session, request, response)
 * ```
 */
interface SessionProviderInterface
{
    public function create(ServerRequestInterface $request): SessionInterface;

    public function commit(SessionInterface $session, ServerRequestInterface $request, ResponseInterface $response): ResponseInterface;
}
