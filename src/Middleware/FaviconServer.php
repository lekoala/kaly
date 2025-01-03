<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;
use Kaly\Router\FaviconProviderInterface;
use Kaly\Core\App;
use Kaly\Http\ResponseException;

/**
 * Add this middleware to prevent unwanted favico.ico requests
 * made by the browser to reach our app controller
 */
class FaviconServer implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path !== '/favicon.ico') {
            throw new SkipMiddlewareException();
        }

        $app = App::inst();
        $provider = $app->getContainer()->get(FaviconProviderInterface::class);

        throw ResponseException::svg($provider->getSvgIcon());
    }
}
