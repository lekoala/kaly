<?php

declare(strict_types=1);

namespace Kaly\Router;

use Exception;
use Kaly\Http\MethodNotAllowedException;
use Kaly\Http\NotFoundException;
use Kaly\Http\RedirectException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Turns a request into a route, and a route back into an url
 */
interface RouterInterface
{
    public const FALLBACK_ACTION = '__invoke';

    /**
     * @throws RedirectException Will be converted to 3xx redirect
     * @throws NotFoundException Will be converted to 404 error
     * @throws MethodNotAllowedException Will be converted to 405 error
     * @throws RouteNotFoundException Will be converted to 404 error
     * @throws Exception Will be converted to 500 error
     */
    public function match(ServerRequestInterface $request): Route;

    /**
     * The url of a named route: 'shop:cart', or 'cart' for the default module
     *
     * @param array<string,mixed> $params Placeholders; the others become the query string
     */
    public function url(string $name, array $params = [], ?string $locale = null): string;

    /**
     * The conventional url of an action
     *
     * @param string|array<mixed> $handler [Controller::class, 'action'], Controller::class or 'Controller::action'
     * @param array<array-key,mixed> $params Action arguments, in order
     */
    public function urlFor(string|array $handler, array $params = [], ?string $locale = null): string;
}
