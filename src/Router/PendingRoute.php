<?php

declare(strict_types=1);

namespace Kaly\Router;

/**
 * Fluent configuration of a single route draft.
 *
 * Returned by Routes::get()/post()/.../map(). Only per-route concerns live
 * here; structural composition (groups, prefixes) belongs to Routes.
 */
final class PendingRoute
{
    public function __construct(
        private Routes $routes,
        private int $index,
    ) {}

    public function name(string $name): self
    {
        $draft = $this->routes->draft($this->index);
        $draft['name'] = $name;
        $this->routes->replaceDraft($this->index, $draft);
        return $this;
    }

    public function where(string $param, string $regex): self
    {
        $draft = $this->routes->draft($this->index);
        $draft['requirements'][$param] = $regex;
        $this->routes->replaceDraft($this->index, $draft);
        return $this;
    }

    /**
     * @param array<string,string> $requirements Param name => PCRE fragment.
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
        $draft = $this->routes->draft($this->index);
        $draft['middlewares'] = array_values([...$draft['middlewares'], ...$middlewares]);
        $this->routes->replaceDraft($this->index, $draft);
        return $this;
    }

    public function default(string $param, mixed $value): self
    {
        $draft = $this->routes->draft($this->index);
        $draft['defaults'][$param] = $value;
        $this->routes->replaceDraft($this->index, $draft);
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
        $draft = $this->routes->draft($this->index);
        $draft['priority'] = $priority;
        $this->routes->replaceDraft($this->index, $draft);
        return $this;
    }
}
