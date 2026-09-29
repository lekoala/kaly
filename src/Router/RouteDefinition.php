<?php

declare(strict_types=1);

namespace Kaly\Router;

/**
 * An explicit route declaration of a module route table, built by the
 * Routes DSL in the module config.php. Its path is relative to the module
 * entry point (its mount or a claim), with one path per locale if needed.
 *
 * A declaration is not a match: TableResolver turns definitions into a
 * resolved Route per request.
 */
final readonly class RouteDefinition
{
    /**
     * @param string|array<string,string> $path A path, or one path per locale
     * @param class-string $controller
     * @param list<string> $methods HTTP methods, uppercase. Empty = any method.
     * @param array<string,string> $requirements Param name => PCRE fragment (without delimiters).
     * @param array<string,mixed> $defaults Default values for optional placeholders.
     * @param list<class-string> $middlewares Middlewares scoped to this route, run before the controller.
     */
    public function __construct(
        public string|array $path,
        public string $controller,
        public string $action = RouterInterface::FALLBACK_ACTION,
        public array $methods = ['GET'],
        public ?string $name = null,
        public array $requirements = [],
        public array $defaults = [],
        public array $middlewares = [],
        public int $priority = 0,
    ) {}

    /**
     * The paths of the route by locale, '*' when the same path serves every locale
     *
     * @return array<string,string>
     */
    public function paths(): array
    {
        return is_array($this->path) ? $this->path : ['*' => $this->path];
    }

    /**
     * The path for a locale, or the first one when the route has no variant for it.
     * Introspection fallback only: url generation (TableResolver::path) refuses
     * to guess and fails when the route has no variant for the locale.
     */
    public function pathFor(?string $locale): string
    {
        $paths = $this->paths();
        return $paths[$locale ?? '*'] ?? $paths['*'] ?? (string) reset($paths);
    }
}
