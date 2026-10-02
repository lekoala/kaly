<?php

declare(strict_types=1);

namespace Kaly\Router;

/**
 * An explicit route declaration of a module route table, built by the
 * Routes DSL in the module config.php. Its path is relative to the module
 * entry point (its mount or a claim), with one path per locale if needed.
 *
 * Mutable and fluent while the table is declared: `get()` returns the
 * definition itself, so a handle that outlives the group() callback it was
 * created in still configures its own route. Once the Router has registered
 * the table, nothing writes to it again; consumers only read.
 *
 * A declaration is not a match: TableResolver turns definitions into a
 * resolved Route per request.
 */
final class RouteDefinition
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

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Constrain a placeholder: `->where('id', '\d+')`
     */
    public function where(string $param, string $regex): self
    {
        $this->requirements[$param] = $regex;
        return $this;
    }

    /**
     * @param array<string,string> $requirements
     */
    public function wheres(array $requirements): self
    {
        foreach ($requirements as $param => $regex) {
            $this->where($param, $regex);
        }
        return $this;
    }

    /**
     * @param class-string ...$middlewares
     */
    public function middleware(string ...$middlewares): self
    {
        $this->middlewares = array_merge($this->middlewares, array_values($middlewares));
        return $this;
    }

    /**
     * Default value of an optional placeholder
     */
    public function default(string $param, mixed $value): self
    {
        $this->defaults[$param] = $value;
        return $this;
    }

    /**
     * @param array<string,mixed> $defaults
     */
    public function defaults(array $defaults): self
    {
        foreach ($defaults as $param => $value) {
            $this->default($param, $value);
        }
        return $this;
    }

    public function priority(int $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

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
