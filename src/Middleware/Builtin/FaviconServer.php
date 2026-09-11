<?php

declare(strict_types=1);

namespace Kaly\Middleware\Builtin;

use Kaly\Core\App;
use Kaly\Http\ResponseException;
use Kaly\Router\FaviconProviderInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Add this middleware to prevent unwanted favicon.ico requests
 * made by the browser to reach our app controller
 */
class FaviconServer implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();
        if ($path !== '/favicon.ico') {
            return $handler->handle($request);
        }

        $app = App::inst();
        $provider = $app->getContainer()->get(FaviconProviderInterface::class);

        throw ResponseException::svg($provider->getSvgIcon());
    }
}
