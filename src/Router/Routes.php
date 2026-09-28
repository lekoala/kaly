<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Core\Ex;

/**
 * Collects explicit route declarations for one composition scope.
 *
 * Used by per-module routes.php files:
 *
 * ```php
 * return static function (Routes $routes): void {
 *     $routes->get('/patients/{id}', [PatientController::class, 'show'])
 *         ->name('patient.show')
 *         ->where('id', '\d+');
 * };
 * ```
 *
 * Attributes describe one route; this DSL composes routes. Anything an
 * attribute expresses is expressible here (and not the reverse): groups,
 * prefixes and shared middlewares only exist at this level.
 *
 * @see RouteDefinition
 *
 * @phpstan-type RouteDraft array{path:string,controller:class-string,action:string,methods:list<string>,name:?string,requirements:array<string,string>,defaults:array<string,mixed>,middlewares:list<class-string>,priority:int}
 */
final class Routes
{
    /**
     * @var list<RouteDraft>
     */
    private array $drafts = [];

    /**
     * @param list<class-string> $middlewares Middlewares inherited by every route of this scope.
     */
    public function __construct(
        private string $prefix = '',
        private array $middlewares = [],
    ) {}

    /**
     * @param string|array<mixed> $handler
     */
    public function get(string $path, string|array $handler): PendingRoute
    {
        return $this->map(['GET'], $path, $handler);
    }

    /**
     * @param string|array<mixed> $handler
     */
    public function post(string $path, string|array $handler): PendingRoute
    {
        return $this->map(['POST'], $path, $handler);
    }

    /**
     * @param string|array<mixed> $handler
     */
    public function put(string $path, string|array $handler): PendingRoute
    {
        return $this->map(['PUT'], $path, $handler);
    }

    /**
     * @param string|array<mixed> $handler
     */
    public function patch(string $path, string|array $handler): PendingRoute
    {
        return $this->map(['PATCH'], $path, $handler);
    }

    /**
     * @param string|array<mixed> $handler
     */
    public function delete(string $path, string|array $handler): PendingRoute
    {
        return $this->map(['DELETE'], $path, $handler);
    }

    /**
     * @param string[] $methods Uppercase HTTP methods. Empty = any method.
     * @param string|array<mixed> $handler [Controller::class, 'action'], Controller::class (__invoke) or 'Controller::action'.
     */
    public function map(array $methods, string $path, string|array $handler): PendingRoute
    {
        [$controller, $action] = self::normalizeHandler($handler);
        $this->drafts[] = [
            'path' => self::joinPath($this->prefix, $path),
            'controller' => $controller,
            'action' => $action,
            'methods' => array_values(array_unique(array_map(strtoupper(...), $methods))),
            'name' => null,
            'requirements' => [],
            'defaults' => [],
            'middlewares' => $this->middlewares,
            'priority' => 0,
        ];
        return new PendingRoute($this, count($this->drafts) - 1);
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
            $child = new self($this->prefix, $this->middlewares);
            $prefixOrCallback($child);
            $this->merge($child);
            return;
        }
        if (!is_string($prefixOrCallback)) {
            throw new Ex('Routes::group() expects a path prefix as first argument');
        }
        $child = new self(self::joinPath($this->prefix, $prefixOrCallback), $this->middlewares);
        $callback($child);
        $this->merge($child);
    }

    public function prefix(string $prefix): RouteGroup
    {
        return new RouteGroup($this, self::joinPath($this->prefix, $prefix), $this->middlewares);
    }

    /**
     * @param class-string ...$middlewares
     */
    public function middleware(string ...$middlewares): RouteGroup
    {
        return new RouteGroup($this, $this->prefix, array_values([...$this->middlewares, ...$middlewares]));
    }

    /**
     * @return list<RouteDefinition>
     */
    public function definitions(): array
    {
        return array_map(
            static fn(array $d): RouteDefinition => new RouteDefinition(
                $d['path'],
                $d['controller'],
                $d['action'],
                $d['methods'],
                $d['name'],
                $d['requirements'],
                $d['defaults'],
                $d['middlewares'],
                $d['priority'],
            ),
            $this->drafts,
        );
    }

    /**
     * @internal Merges a group scope back into its parent.
     */
    public function merge(self $child): void
    {
        foreach ($child->drafts as $draft) {
            $this->drafts[] = $draft;
        }
    }

    /**
     * Appends an already built definition (eg: AttributeRouteLoader output).
     *
     * @internal Route sources other than this DSL.
     */
    public function addDefinition(RouteDefinition $definition): void
    {
        $this->drafts[] = [
            'path' => $definition->path,
            'controller' => $definition->controller,
            'action' => $definition->action,
            'methods' => $definition->methods,
            'name' => $definition->name,
            'requirements' => $definition->requirements,
            'defaults' => $definition->defaults,
            'middlewares' => $definition->middlewares,
            'priority' => $definition->priority,
        ];
    }

    /**
     * @internal Allows PendingRoute to configure the last added draft.
     * @return RouteDraft
     */
    public function draft(int $index): array
    {
        return $this->drafts[$index];
    }

    /**
     * @internal
     * @param RouteDraft $draft
     */
    public function replaceDraft(int $index, array $draft): void
    {
        $drafts = $this->drafts;
        $drafts[$index] = $draft;
        // Index assignment widens list to array; keys are 0..n-1 by construction.
        $this->drafts = array_values($drafts);
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
        if (!$method->isPublic() || $method->isStatic()) {
            throw new Ex("Route handler '{$class}::{$action}' must be a public non-static method");
        }
        if (str_starts_with($action, '__') && $action !== RouterInterface::FALLBACK_ACTION) {
            throw new Ex("Route handler '{$class}::{$action}' is not an admissible action");
        }
        return [$class, $action];
    }

    private static function joinPath(string $prefix, string $path): string
    {
        $joined = rtrim($prefix, '/') . '/' . ltrim($path, '/');
        return $joined === '/' ? '/' : rtrim($joined, '/');
    }
}
