<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Ex;
use Kaly\Http\Method;
use Psr\Http\Server\MiddlewareInterface;

/**
 * Collects the route declarations of a module entry point, in config.php:
 *
 * ```php
 * $module->routes(function (Routes $routes): void {
 *     $routes->get('/orders/{id}', [OrderController::class, 'show'])
 *         ->name('order.show')
 *         ->where('id', '\d+');
 *     $routes->get(['fr' => '/a-propos', 'en' => '/about'], AboutController::class)->name('about');
 * });
 * ```
 *
 * Paths are relative to the module entry point. Groups, prefixes and shared
 * middlewares compose routes.
 *
 * @see RouteDefinition
 */
final class Routes
{
    /**
     * @var list<RouteDefinition>
     */
    private array $definitions = [];

    /**
     * The scope this one was derived from by prefix() or middleware(), so a
     * group declared on it still lands in the routes the caller started with.
     */
    private ?self $parent = null;

    /**
     * @param list<class-string> $middlewares Middlewares inherited by every route of this scope.
     */
    public function __construct(
        private string $prefix = '',
        private array $middlewares = [],
    ) {}

    /**
     * @param string|array<string,string> $path A path, or one path per locale: ['fr' => '/a-propos', 'en' => '/about']
     * @param string|array<mixed> $handler
     */
    public function get(string|array $path, string|array $handler): RouteDefinition
    {
        return $this->map(['GET'], $path, $handler);
    }

    /**
     * @param string|array<string,string> $path
     * @param string|array<mixed> $handler
     */
    public function post(string|array $path, string|array $handler): RouteDefinition
    {
        return $this->map(['POST'], $path, $handler);
    }

    /**
     * @param string|array<string,string> $path
     * @param string|array<mixed> $handler
     */
    public function put(string|array $path, string|array $handler): RouteDefinition
    {
        return $this->map(['PUT'], $path, $handler);
    }

    /**
     * @param string|array<string,string> $path
     * @param string|array<mixed> $handler
     */
    public function patch(string|array $path, string|array $handler): RouteDefinition
    {
        return $this->map(['PATCH'], $path, $handler);
    }

    /**
     * @param string|array<string,string> $path
     * @param string|array<mixed> $handler
     */
    public function delete(string|array $path, string|array $handler): RouteDefinition
    {
        return $this->map(['DELETE'], $path, $handler);
    }

    /**
     * @param string[] $methods Uppercase HTTP methods. Empty = any method.
     * @param string|array<string,string> $path A path, or one path per locale
     * @param string|array<mixed> $handler [Controller::class, 'action'], Controller::class (__invoke) or 'Controller::action'.
     */
    public function map(array $methods, string|array $path, string|array $handler): RouteDefinition
    {
        $this->assertDeclarable();
        [$controller, $action] = self::normalizeHandler($handler);
        $definition = new RouteDefinition(
            is_array($path)
                ? array_map(fn(string $p): string => RoutePath::join($this->prefix, $p), $path)
                : RoutePath::join($this->prefix, $path),
            $controller,
            $action,
            Method::normalizeList($methods),
            middlewares: $this->middlewares,
        );
        $this->definitions[] = $definition;
        return $definition;
    }

    /**
     * Group routes under a path prefix:
     * `$routes->group('/api', function (Routes $routes): void {...});`
     *
     * Or with a scoped builder for shared middlewares:
     * `$routes->prefix('/staff')->middleware(Staff::class)->group(function (Routes $routes): void {...});`
     */
    public function group(string|callable $prefixOrCallback, ?callable $callback = null): void
    {
        if ($callback === null) {
            if (!is_callable($prefixOrCallback)) {
                throw new Ex('Routes::group() expects a callable or a path prefix with a callable');
            }
            $declare = $prefixOrCallback;
            $prefix = $this->prefix;
        } else {
            if (!is_string($prefixOrCallback)) {
                throw new Ex('Routes::group() expects a path prefix as first argument');
            }
            $declare = $callback;
            $prefix = RoutePath::join($this->prefix, $prefixOrCallback);
        }

        $child = new self($prefix, $this->middlewares);
        $declare($child);
        $this->root()->merge($child);
    }

    /**
     * A scoped view sharing the middlewares, with a longer path prefix.
     *
     * Declare the routes with group(), as `$routes->prefix('/api')->group(…)`:
     * the scope writes into the routes it was derived from.
     */
    public function prefix(string $prefix): self
    {
        $child = new self(RoutePath::join($this->prefix, $prefix), $this->middlewares);
        $child->parent = $this;
        return $child;
    }

    /**
     * A scoped view sharing the path prefix, with extra shared middlewares.
     *
     * @param class-string ...$middlewares
     */
    public function middleware(string ...$middlewares): self
    {
        $child = new self($this->prefix, array_merge($this->middlewares, array_values($middlewares)));
        $child->parent = $this;
        return $child;
    }

    /**
     * @return list<RouteDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * The routes the caller started with, however many scopes were derived.
     */
    private function root(): self
    {
        return $this->parent?->root() ?? $this;
    }

    /**
     * A scope derived by prefix() or middleware() only declares through
     * group(), which merges back into the root. Declaring directly on it would
     * write into a view nobody reads, silently dropping the route.
     */
    private function assertDeclarable(): void
    {
        if ($this->parent !== null) {
            throw new Ex('Routes::prefix() and middleware() scopes only declare routes through group()');
        }
    }

    /**
     * Absorbs a group scope. Always called on the root, never on a derived view.
     */
    private function merge(self $child): void
    {
        foreach ($child->definitions as $definition) {
            $this->definitions[] = $definition;
        }
    }

    /**
     * Appends an already built definition.
     *
     * For a route source that is not this DSL: a database, a generated
     * config file, an external schema.
     */
    public function addDefinition(RouteDefinition $definition): void
    {
        $this->assertDeclarable();
        $this->definitions[] = $definition;
    }

    /**
     * Normalizes a handler to a [controller, action] pair and enforces the
     * admissibility rule: an explicit mapping never makes a method executable,
     * it only maps to an action that is already admissible by normal
     * controller rules (public, non-static, non-magic except __invoke).
     *
     * @param string|array<mixed> $handler
     * @return array{0:class-string,1:string}
     */
    public static function normalizeHandler(string|array $handler): array
    {
        if (is_string($handler)) {
            $handler = str_replace('->', '::', $handler);
            $parts = str_contains($handler, '::') ? explode('::', $handler, 2) : [$handler];
            $class = $parts[0];
            $action = $parts[1] ?? RouterInterface::FALLBACK_ACTION;
        } elseif (array_is_list($handler)) {
            $class = $handler[0] ?? null;
            $action = $handler[1] ?? RouterInterface::FALLBACK_ACTION;
        } else {
            throw new Ex('Route handler must be [Controller::class, \'action\'], Controller::class or \'Controller::action\'');
        }
        if (!is_string($class) || $class === '' || !is_string($action) || $action === '') {
            throw new Ex('Route handler must be [Controller::class, \'action\'], Controller::class or \'Controller::action\'');
        }
        if (!class_exists($class)) {
            throw new Ex("Route handler class '{$class}' does not exist");
        }
        if (!method_exists($class, $action)) {
            throw new Ex("Route handler '{$class}::{$action}' does not exist");
        }
        $method = new \ReflectionMethod($class, $action);
        if (!ActionSignature::isAdmissible($method)) {
            throw new Ex("Route handler '{$class}::{$action}' must be a public, non-static method, non-magic except __invoke");
        }
        return [$class, $action];
    }

    /**
     * Merges explicit middleware declarations, keeping the first occurrence
     * of each middleware so that the same guard declared at two levels runs
     * once, at its outermost position.
     *
     * @param list<class-string> ...$lists
     * @return list<class-string>
     */
    public static function mergeMiddlewares(array ...$lists): array
    {
        $merged = [];
        foreach ($lists as $list) {
            foreach ($list as $middleware) {
                if (!in_array($middleware, $merged, true)) {
                    $merged[] = $middleware;
                }
            }
        }
        return $merged;
    }

    /**
     * Validates explicit middleware declarations: a declared middleware
     * always runs or fails loudly, never silently ignored.
     *
     * @param list<string> $middlewares
     * @return list<class-string>
     */
    public static function normalizeMiddlewares(array $middlewares, string $declaredOn): array
    {
        $valid = [];
        foreach ($middlewares as $middleware) {
            if (!class_exists($middleware)) {
                throw new Ex("Middleware '{$middleware}' declared on '{$declaredOn}' does not exist");
            }
            if (!is_a($middleware, MiddlewareInterface::class, true)) {
                throw new Ex("Middleware '{$middleware}' declared on '{$declaredOn}' is not a PSR-15 request middleware");
            }
            $valid[] = $middleware;
        }
        return self::mergeMiddlewares($valid);
    }
}
