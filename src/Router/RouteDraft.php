<?php

declare(strict_types=1);

namespace Kaly\Router;

/**
 * One route declaration while it is being built.
 *
 * A plain mutable object rather than an array slot addressed by index: a
 * `PendingRoute` holds the draft itself, so a handle that outlives the
 * `group()` callback it was created in still configures the route it belongs
 * to. With an index, a group could absorb a copy and the late mutation was
 * silently dropped.
 *
 * Frozen into a `RouteDefinition` when the table compiles.
 *
 * @see Routes
 */
final class RouteDraft
{
    /**
     * @param string|array<string,string> $path One path, or one per locale
     * @param class-string $controller
     * @param list<string> $methods
     * @param array<string,string> $requirements Param name => PCRE fragment
     * @param array<string,mixed> $defaults
     * @param list<class-string> $middlewares
     */
    public function __construct(
        public string|array $path,
        public readonly string $controller,
        public readonly string $action,
        public readonly array $methods,
        public ?string $name = null,
        public array $requirements = [],
        public array $defaults = [],
        public array $middlewares = [],
        public int $priority = 0,
    ) {}

    public function definition(): RouteDefinition
    {
        return new RouteDefinition(
            $this->path,
            $this->controller,
            $this->action,
            $this->methods,
            $this->name,
            $this->requirements,
            $this->defaults,
            $this->middlewares,
            $this->priority,
        );
    }
}
