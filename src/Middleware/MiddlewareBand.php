<?php

declare(strict_types=1);

namespace Kaly\Middleware;

/**
 * The middleware phases of a kaly request.
 *
 * The order is fixed by the framework and is not configurable:
 *
 * ```text
 * incoming -> routing -> routed -> dispatcher -> outgoing
 * ```
 *
 * Inside a band, middlewares run by ascending priority, then by registration
 * order. There is deliberately no before()/after()/requires() dependency graph.
 *
 * `Incoming` and `Routed` are request middlewares: they receive a request and
 * may short-circuit with a response. `Outgoing` is a response phase: it runs
 * once the response exists, whatever its origin (happy path, short-circuit or
 * exception), and transforms it into the response that really leaves.
 */
enum MiddlewareBand: string
{
    /**
     * Runs before any routing happened: trusted proxies, request id, static
     * files, global rate limits...
     */
    case Incoming = 'incoming';

    /**
     * Runs once the route and the locale are known: auth, authorization, CSRF,
     * per route rate limits...
     */
    case Routed = 'routed';

    /**
     * Runs on the response, after the whole request cycle produced one. It is
     * executed exactly once on any response, including error responses built
     * by the kernel: webp conversion, gzip, cache headers...
     */
    case Outgoing = 'outgoing';
}
