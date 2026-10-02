<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Ex;
use Psr\Http\Server\MiddlewareInterface;
use ReflectionClass;

/**
 * Resolves the middlewares scoped to a route.
 *
 * A declared middleware is always executed or refused loudly: an unknown
 * class or a class that is not a request middleware fails instead of being
 * silently ignored (an ignored auth middleware is an open door).
 */
final class RouteMiddlewares
{
    /**
     * Controller declarations never change during the life of the process
     *
     * @var array<string,list<class-string>>
     */
    private static array $cache = [];

    /**
     * The `#[Middleware]` declarations of an action, outermost first:
     * parent classes, the class, then the method.
     *
     * @param class-string $controller
     * @return list<class-string>
     */
    public static function ofAction(string $controller, string $action): array
    {
        $key = $controller . '::' . $action;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $class = new ReflectionClass($controller);
        $lineage = [];
        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            array_unshift($lineage, $current);
        }

        $declared = [];
        foreach ($lineage as $reflection) {
            foreach ($reflection->getAttributes(Middleware::class) as $attribute) {
                $declared = [...$declared, ...$attribute->newInstance()->middlewares];
            }
        }
        if ($class->hasMethod($action)) {
            foreach ($class->getMethod($action)->getAttributes(Middleware::class) as $attribute) {
                $declared = [...$declared, ...$attribute->newInstance()->middlewares];
            }
        }

        return self::$cache[$key] = self::normalize($declared, $key);
    }

    /**
     * Merge declarations, keeping the first occurrence of each middleware so
     * that the same guard declared at two levels runs once, at the outermost
     * position.
     *
     * @param list<class-string> ...$lists
     * @return list<class-string>
     */
    public static function merge(array ...$lists): array
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
     * @param list<string> $middlewares
     * @return list<class-string>
     */
    public static function normalize(array $middlewares, string $declaredOn): array
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
        return self::merge($valid);
    }
}
