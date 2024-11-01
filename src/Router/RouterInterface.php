<?php

declare(strict_types=1);

namespace Kaly\Router;

use Exception;
use Psr\Http\Message\ServerRequestInterface;
use Kaly\Http\RedirectException;
use Kaly\Http\NotFoundException;

/**
 * Routers should implement a simple "match" function
 * that should return a route that will be processed by the app
 */
interface RouterInterface
{
    public const MODULE = "module";
    public const NAMESPACE = "namespace";
    public const CONTROLLER = "controller";
    public const ACTION = "action";
    public const PARAMS = "params";
    public const LOCALE = "locale";
    public const SEGMENTS = "segments";
    public const TEMPLATE = "template";
    public const FALLBACK_ACTION = "__invoke";

    /**
     * @throws RedirectException Will be converted to 3xx redirect
     * @throws NotFoundException Will be converted to 404 error
     * @throws RouteNotFoundException Will be converted to 404 error
     * @throws Exception Will be converted to 500 error
     */
    public function match(ServerRequestInterface $request): Route;

    /**
     * @param string|array<mixed> $handler
     * @param array<string,mixed> $params
     * @return string
     */
    public function generate($handler, array $params = []): string;
}
