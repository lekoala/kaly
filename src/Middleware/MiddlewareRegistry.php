<?php

declare(strict_types=1);

namespace Kaly\Middleware;

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
 * An outgoing middleware runs on the response, whatever its origin. Its
 * condition receives the current response instead of a request:
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
final class MiddlewareRegistry
{
    /**
     * @var array<string,list<MiddlewareEntry>>
     */
    private array $entries = [];

    /**
     * @var array<string,list<MiddlewareEntry>>
     */
    private array $sorted = [];

    private int $sequence = 0;

    /**
     * Add a middleware that runs before routing
     *
     * @param class-string|MiddlewareInterface|GeneratorMiddlewareInterface $middleware
     * @param Closure|null $when Receives the context and the container, returning false skips the middleware
     */
    public function incoming(
        string|MiddlewareInterface|GeneratorMiddlewareInterface $middleware,
        int $priority = 0,
        ?Closure $when = null,
    ): self {
        return $this->add(MiddlewareBand::Incoming, $middleware, $priority, $when);
    }

    /**
     * Add a middleware that runs once the route is known
     *
     * @param class-string|MiddlewareInterface|GeneratorMiddlewareInterface $middleware
     * @param Closure|null $when Receives the context and the container, returning false skips the middleware
     */
    public function routed(
        string|MiddlewareInterface|GeneratorMiddlewareInterface $middleware,
        int $priority = 0,
        ?Closure $when = null,
    ): self {
        return $this->add(MiddlewareBand::Routed, $middleware, $priority, $when);
    }

    /**
     * Add a middleware that runs on the response once the whole request cycle
     * produced one, whatever its origin (happy path, short-circuit, exception).
     *
     * @param class-string|OutgoingMiddlewareInterface $middleware
     * @param Closure|null $when Receives the current response, the context and the container; returning false skips the middleware
     */
    public function outgoing(string|OutgoingMiddlewareInterface $middleware, int $priority = 0, ?Closure $when = null): self
    {
        return $this->add(MiddlewareBand::Outgoing, $middleware, $priority, $when);
    }

    /**
     * @param class-string|MiddlewareInterface|GeneratorMiddlewareInterface|OutgoingMiddlewareInterface $middleware
     */
    public function add(
        MiddlewareBand $band,
        string|MiddlewareInterface|GeneratorMiddlewareInterface|OutgoingMiddlewareInterface $middleware,
        int $priority = 0,
        ?Closure $when = null,
    ): self {
        $this->entries[$band->value][] = new MiddlewareEntry($middleware, $priority, $when, $this->sequence++);
        unset($this->sorted[$band->value]);

        return $this;
    }

    /**
     * The ordered entries of a band
     *
     * @return list<MiddlewareEntry>
     */
    public function band(MiddlewareBand $band): array
    {
        $cached = $this->sorted[$band->value] ?? null;
        if ($cached !== null) {
            return $cached;
        }

        $entries = $this->entries[$band->value] ?? [];
        usort(
            $entries,
            static fn(MiddlewareEntry $a, MiddlewareEntry $b): int => [$a->priority, $a->sequence] <=> [$b->priority, $b->sequence],
        );

        return $this->sorted[$band->value] = $entries;
    }

    /**
     * @param class-string $middlewareClass
     */
    public function has(string $middlewareClass, ?MiddlewareBand $band = null): bool
    {
        $bands = $band === null ? MiddlewareBand::cases() : [$band];

        foreach ($bands as $case) {
            foreach ($this->entries[$case->value] ?? [] as $entry) {
                if ($entry->is($middlewareClass)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function clear(?MiddlewareBand $band = null): self
    {
        $bands = $band === null ? MiddlewareBand::cases() : [$band];

        foreach ($bands as $case) {
            unset($this->entries[$case->value], $this->sorted[$case->value]);
        }

        return $this;
    }

    /**
     * @return array<string,list<class-string|MiddlewareInterface|GeneratorMiddlewareInterface|OutgoingMiddlewareInterface>>
     */
    public function toArray(): array
    {
        $all = [];
        foreach (MiddlewareBand::cases() as $case) {
            $all[$case->value] = array_map(static fn(MiddlewareEntry $entry) => $entry->middleware, $this->band($case));
        }

        return $all;
    }
}
