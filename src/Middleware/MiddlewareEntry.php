<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use Closure;
use Psr\Http\Server\MiddlewareInterface;

/**
 * @internal A registered middleware with its ordering and its condition
 */
final class MiddlewareEntry
{
    /**
     * @param class-string|MiddlewareInterface|GeneratorMiddlewareInterface|OutgoingMiddlewareInterface $middleware
     * @param Closure(\Kaly\Core\HttpContext, ?\Psr\Container\ContainerInterface): bool|null $condition
     * @param int $sequence Registration order, used to keep the sort stable
     */
    public function __construct(
        public readonly string|MiddlewareInterface|GeneratorMiddlewareInterface|OutgoingMiddlewareInterface $middleware,
        public readonly int $priority = 0,
        public readonly ?Closure $condition = null,
        public readonly int $sequence = 0,
    ) {}

    /**
     * @param class-string $class
     */
    public function is(string $class): bool
    {
        if (is_string($this->middleware)) {
            return $this->middleware === $class || is_a($this->middleware, $class, true);
        }

        return $this->middleware instanceof $class;
    }
}
