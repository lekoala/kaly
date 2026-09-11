<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use Closure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @internal Wraps a callable into a PSR-15 RequestHandler.
 */
final class CallableToHandlerAdapter implements RequestHandlerInterface
{
    /** @var Closure(ServerRequestInterface): ResponseInterface */
    private Closure $callable;

    /**
     * @param callable(ServerRequestInterface): ResponseInterface $callable
     */
    public function __construct(callable $callable)
    {
        $this->callable = Closure::fromCallable($callable);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return ($this->callable)($request);
    }
}
