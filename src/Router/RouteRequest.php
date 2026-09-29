<?php

declare(strict_types=1);

namespace Kaly\Router;

use Psr\Http\Message\ServerRequestInterface;

/**
 * What a module resolver receives: the part of the url below the entry point
 * of the module (its mount or one of its claims), once the locale is known.
 *
 * ```text
 * /fr/boutique/panier/ajouter/42
 *  locale  prefix     segments
 *  'fr'    '/fr/boutique'  ['panier', 'ajouter', '42']
 * ```
 */
final readonly class RouteRequest
{
    /**
     * @param list<string> $segments The path segments left to resolve
     * @param string $prefix The consumed part of the path (locale and entry point)
     * @param string $module The module namespace
     */
    public function __construct(
        public ServerRequestInterface $request,
        public array $segments,
        public string $prefix,
        public string $module,
        public ?string $locale = null,
    ) {}

    /**
     * The path left to resolve, always starting with a slash
     */
    public function path(): string
    {
        return '/' . implode('/', $this->segments);
    }

    public function method(): string
    {
        return strtoupper($this->request->getMethod());
    }

    /**
     * Build the final route of this request: the module and the locale are
     * already known, so resolvers never complete a mutable draft afterwards.
     *
     * @param class-string $controller
     * @param array<int<0,max>|string,mixed> $params Action arguments
     * @param array<string,mixed> $bindings Controller constructor arguments, by name
     * @param string|null $name The qualified name of the route (`module:name`), if it has one
     * @param list<class-string> $middlewares The middlewares scoped to this route, outermost first
     * @param class-string<\Kaly\Http\RequestInput>|null $inputClass The trailing input of the action
     */
    public function route(
        string $controller,
        string $action = RouterInterface::FALLBACK_ACTION,
        array $params = [],
        array $bindings = [],
        ?string $name = null,
        array $middlewares = [],
        ?string $inputClass = null,
    ): Route {
        return new Route($controller, $action, $params, $bindings, $name, $middlewares, $inputClass, $this->locale, $this->module);
    }
}
