<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @internal A handler that throws an exception if called. Used for terminating middlewares.
 */
final class NullHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        throw new LogicException('The final handler should not call the next handler.');
    }
}
