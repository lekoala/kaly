<?php

declare(strict_types=1);

namespace Kaly\Http\Middleware;

use Kaly\Http\Exception\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class PreventFileAccess implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // Prevent other file requests to go through routing
        $basePath = basename($path);
        if (str_contains($basePath, '.') && !str_ends_with($path, '/')) {
            throw new NotFoundException('File not found');
        }

        return $handler->handle($request);
    }
}
