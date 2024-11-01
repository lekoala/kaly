<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Kaly\Http\NotFoundException;

class PreventFileAccess implements MiddlewareInterface, LinearMiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // Prevent other file requests to go through routing
        $basePath = basename($path);
        if (str_contains($basePath, ".") && !str_ends_with($path, '/')) {
            throw new NotFoundException("File not found");
        }

        throw new SkipMiddlewareException();
    }
}
