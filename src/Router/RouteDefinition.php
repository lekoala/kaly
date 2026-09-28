<?php

declare(strict_types=1);

namespace Kaly\Router;

/**
 * An explicit route declaration.
 *
 * Produced identically by per-module routes.php files (through the Routes DSL) and
 * by `#[Route]` (through AttributeRouteLoader): the attribute is local sugar
 * for exactly this model, never a second routing system. Anything an
 * attribute expresses is expressible through Routes; structural composition
 * (groups, prefixes, shared middlewares) belongs to routes.php.
 *
 * A declaration is not a match: RouteCollectionRouter turns definitions into
 * a resolved Route per request.
 */
final readonly class RouteDefinition
{
    /**
     * @param class-string $controller
     * @param list<string> $methods HTTP methods, uppercase. Empty = any method.
     * @param array<string,string> $requirements Param name => PCRE fragment (without delimiters).
     * @param array<string,mixed> $defaults Default values for optional placeholders.
     * @param list<class-string> $middlewares Middlewares scoped to this route,
     *   enforced by a routed middleware reading Route::$definition.
     */
    public function __construct(
        public string $path,
        public string $controller,
        public string $action = RouterInterface::FALLBACK_ACTION,
        public array $methods = ['GET'],
        public ?string $name = null,
        public array $requirements = [],
        public array $defaults = [],
        public array $middlewares = [],
        public int $priority = 0,
    ) {}
}
