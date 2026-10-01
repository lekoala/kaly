<?php

declare(strict_types=1);

namespace Kaly\Core\Middleware;

use Closure;
use Psr\Http\Server\MiddlewareInterface;

/**
 * The configuration of the middleware pipeline.
 *
 * Instead of a single flat list whose order is implicit, middlewares are
 * registered in one of the phases surrounding the routing step:
 *
 * ```php
 * $app->middleware()
 *     ->incoming(TrustedProxy::class)
 *     ->incoming(RequestId::class)
 *     ->routed(AuthMiddleware::class, priority: 100)
 *     ->routed(RateLimitMiddleware::class, priority: 200);
 * ```
 *
 * A routed middleware runs with a context that already knows the route, so
 * conditions can be expressed on established state:
 *
 * ```php
 * $app->middleware()->routed(
 *     AdminAuth::class,
 *     when: static fn(HttpContext $ctx): bool => $ctx->route()->module === 'Admin',
 * );
 * ```
 *
 * An outgoing middleware runs on the response, whatever its origin. It can
 * be a plain closure, and its condition receives the current response
 * instead of a request:
 *
 * ```php
 * $app->middleware()->outgoing(
 *     WebpResponse::class,
 *     when: static fn(ResponseInterface $response): bool => $response->getStatusCode() === 200,
 * );
 * ```
 *
 * Ordering rules, and nothing more:
 * - the phase order is fixed: incoming, then routing, then routed, then outgoing
 * - inside a phase, priority ascending, then registration order
 */
final class Registry
{
    /**
     * @var array<string,list<Entry>>
     */
    private array $entries = [];

    /**
     * @var array<string,list<Entry>>
     */
    private array $sorted = [];

    private int $sequence = 0;

    /**
     * Add a middleware that runs before routing
     *
     * @param class-string|MiddlewareInterface $middleware
     * @param Closure(\Kaly\Core\HttpContext, ?\Psr\Container\ContainerInterface): bool|null $when Receives the context and the container, returning false skips the middleware
     */
    public function incoming(string|MiddlewareInterface $middleware, int $priority = 0, ?Closure $when = null): self
    {
        return $this->add(Band::Incoming, $middleware, $priority, $when);
    }

    /**
     * Add a middleware that runs once the route is known
     *
     * @param class-string|MiddlewareInterface $middleware
     * @param Closure(\Kaly\Core\HttpContext, ?\Psr\Container\ContainerInterface): bool|null $when Receives the context and the container, returning false skips the middleware
     */
    public function routed(string|MiddlewareInterface $middleware, int $priority = 0, ?Closure $when = null): self
    {
        return $this->add(Band::Routed, $middleware, $priority, $when);
    }

    /**
     * Add a middleware that runs on the response once the whole request cycle
     * produced one, whatever its origin (happy path, short-circuit, exception).
     *
     * With `always: true` the middleware is a guarantee rather than a step:
     * it also runs on the error response that replaces a failed outgoing
     * phase or a failed final commit, and its own failure is reported without
     * ever replacing the response. Because it can run more than once for a
     * single request, an `always` transformation must stay free of side
     * effects and return a response derived from the one it received. Use it
     * for headers that must be on every response (security headers, request
     * id, audit).
     *
     * @param class-string|OutgoingInterface|Closure(\Psr\Http\Message\ResponseInterface, \Kaly\Core\HttpContext): \Psr\Http\Message\ResponseInterface $middleware
     * @param Closure(\Psr\Http\Message\ResponseInterface, \Kaly\Core\HttpContext, ?\Psr\Container\ContainerInterface): bool|null $when Receives the current response, the context and the container; returning false skips the middleware
     */
    public function outgoing(
        string|OutgoingInterface|Closure $middleware,
        int $priority = 0,
        ?Closure $when = null,
        bool $always = false,
    ): self {
        if ($middleware instanceof Closure) {
            $middleware = new ClosureOutgoing($middleware);
        }
        return $this->add(Band::Outgoing, $middleware, $priority, $when, $always);
    }

    /**
     * Generic entry point. The typed facades (incoming, routed, outgoing) are
     * the intended API; this stays wide so both runners can share one registry.
     *
     * @param class-string|MiddlewareInterface|OutgoingInterface $middleware
     * @param (
     *     Closure(\Kaly\Core\HttpContext, ?\Psr\Container\ContainerInterface): bool
     *     |Closure(\Psr\Http\Message\ResponseInterface, \Kaly\Core\HttpContext, ?\Psr\Container\ContainerInterface): bool
     * )|null $when Band-dependent condition, see Entry
     */
    public function add(
        Band $band,
        string|MiddlewareInterface|OutgoingInterface $middleware,
        int $priority = 0,
        ?Closure $when = null,
        bool $always = false,
    ): self {
        if ($always && $band !== Band::Outgoing) {
            throw new \InvalidArgumentException('Only an outgoing middleware can be marked always');
        }
        $this->entries[$band->value][] = new Entry($middleware, $priority, $when, $this->sequence++, $always);
        unset($this->sorted[$band->value]);

        return $this;
    }

    /**
     * The ordered entries of a band
     *
     * @return list<Entry>
     */
    public function band(Band $band): array
    {
        $cached = $this->sorted[$band->value] ?? null;
        if ($cached !== null) {
            return $cached;
        }

        $entries = $this->entries[$band->value] ?? [];
        usort($entries, static fn(Entry $a, Entry $b): int => [$a->priority, $a->sequence] <=> [$b->priority, $b->sequence]);

        return $this->sorted[$band->value] = $entries;
    }

    /**
     * @param class-string $middlewareClass
     */
    public function has(string $middlewareClass, ?Band $band = null): bool
    {
        $bands = $band === null ? Band::cases() : [$band];

        foreach ($bands as $case) {
            foreach ($this->entries[$case->value] ?? [] as $entry) {
                if ($entry->is($middlewareClass)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function clear(?Band $band = null): self
    {
        $bands = $band === null ? Band::cases() : [$band];

        foreach ($bands as $case) {
            unset($this->entries[$case->value], $this->sorted[$case->value]);
        }

        return $this;
    }

    /**
     * @return array<string,list<class-string|MiddlewareInterface|OutgoingInterface>>
     */
    public function toArray(): array
    {
        $all = [];
        foreach (Band::cases() as $case) {
            $all[$case->value] = array_map(static fn(Entry $entry) => $entry->middleware, $this->band($case));
        }

        return $all;
    }
}
