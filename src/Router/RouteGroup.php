<?php

declare(strict_types=1);

namespace Kaly\Router;

/**
 * A scoped composition context: path prefix and shared middlewares applied
 * to every route declared inside group().
 *
 * ```php
 * $routes->prefix('/staff')->middleware(Staff::class)->group(function (Routes $routes): void {
 *     $routes->get('/agenda', [AgendaController::class, 'index']);
 * });
 * ```
 */
final class RouteGroup
{
    /**
     * @param list<class-string> $middlewares
     */
    public function __construct(
        private Routes $routes,
        private string $prefix,
        private array $middlewares,
    ) {}

    public function group(callable $callback): void
    {
        $child = new Routes($this->prefix, $this->middlewares);
        $callback($child);
        $this->routes->merge($child);
    }

    public function prefix(string $prefix): self
    {
        return new self($this->routes, rtrim($this->prefix, '/') . '/' . ltrim($prefix, '/'), $this->middlewares);
    }

    /**
     * @param class-string ...$middlewares
     */
    public function middleware(string ...$middlewares): self
    {
        return new self($this->routes, $this->prefix, array_values([...$this->middlewares, ...$middlewares]));
    }
}
