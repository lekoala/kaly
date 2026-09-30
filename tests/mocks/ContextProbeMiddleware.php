<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use Closure;
use Kaly\Http\HttpContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Lets a test observe the context as it is when a given band runs
 */
class ContextProbeMiddleware implements MiddlewareInterface
{
    /**
     * @param Closure(HttpContext): void $probe
     */
    public function __construct(
        private Closure $probe,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        ($this->probe)(HttpContext::from($request));

        return $handler->handle($request);
    }
}
