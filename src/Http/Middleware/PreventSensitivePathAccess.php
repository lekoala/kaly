<?php

declare(strict_types=1);

namespace Kaly\Http\Middleware;

use Kaly\Http\Exception\NotFoundException;
use Kaly\Http\SensitivePathPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class PreventSensitivePathAccess implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (SensitivePathPolicy::isSensitive($request->getUri()->getPath())) {
            throw new NotFoundException('File not found');
        }

        return $handler->handle($request);
    }
}
