<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @internal Wraps a terminating PSR-15 Middleware into a RequestHandler.
 */
final class MiddlewareToHandlerAdapter implements RequestHandlerInterface
{
    private MiddlewareInterface $middleware;

    public function __construct(MiddlewareInterface $middleware)
    {
        $this->middleware = $middleware;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->middleware->process($request, new NullHandler());
    }
}
