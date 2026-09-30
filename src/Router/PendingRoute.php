<?php

declare(strict_types=1);

namespace Kaly\Router;

/**
 * Fluent configuration of a single route declaration.
 *
 * Returned by Routes::get()/post()/.../map(). Only per-route concerns live
 * here; structural composition (groups, prefixes) belongs to Routes.
 *
 * It holds the draft itself, not an index into it, so a handle that outlives
 * the group() callback it was created in still configures its own route.
 */
final class PendingRoute
{
    public function __construct(
        private RouteDraft $draft,
    ) {}

    public function name(string $name): self
    {
        $this->draft->name = $name;
        return $this;
    }

    public function where(string $param, string $regex): self
    {
        $this->draft->requirements[$param] = $regex;
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
        $this->draft->middlewares = array_values([...$this->draft->middlewares, ...$middlewares]);
        return $this;
    }

    public function default(string $param, mixed $value): self
    {
        $this->draft->defaults[$param] = $value;
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
        $this->draft->priority = $priority;
        return $this;
    }
}
