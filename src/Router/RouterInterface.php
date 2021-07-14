<?php

declare(strict_types=1);

namespace Kaly\Router;

use Exception;
use Stringable;
use Kaly\Exceptions\RouterException;
use Psr\Container\ContainerInterface;
use Kaly\Exceptions\RedirectException;
use Psr\Http\Message\ResponseInterface;
use Kaly\Exceptions\ValidationException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Routers should implement a simple "match" function
 * that should return something that is processable
 * by our app
 */
interface RouterInterface
{
    /**
     * @throws RedirectException Will be converted to redirect
     * @throws ValidationException Will be converted to 403 error
     * @throws RouterException Will be converted to 404 error
     * @throws Exception Will be converted to 500 error
     * @return ResponseInterface|string|Stringable|array<string, mixed>
     */
    public function match(ServerRequestInterface $request, ContainerInterface $di = null);
}
