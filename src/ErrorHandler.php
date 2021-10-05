<?php

declare(strict_types=1);

namespace Kaly;

use Throwable;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class ErrorHandler implements MiddlewareInterface
{
    protected App $app;
    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $ex) {
            $code = 500;
            $body = $this->app->getDebug() ? $ex->getMessage() : 'Server error';
            return $this->app->prepareResponse($request, [], $body, $code);
        }
    }
}