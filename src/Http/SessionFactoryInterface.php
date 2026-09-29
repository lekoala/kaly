<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * How the session of a request is obtained.
 *
 * This deliberately normalizes nothing about storage: it only says where the
 * session of a request comes from, so sequential and concurrent runtimes each
 * pick what fits. The default serves native PHP sessions; a worker or a test
 * binds a factory returning a request-scoped implementation instead.
 */
interface SessionFactoryInterface
{
    public function create(ServerRequestInterface $request): SessionInterface;
}
