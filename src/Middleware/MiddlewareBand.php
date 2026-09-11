<?php

declare(strict_types=1);

namespace Kaly\Middleware;

/**
 * The two middleware bands of a kaly request.
 *
 * The order is fixed by the framework and is not configurable:
 *
 * ```text
 * incoming -> routing -> routed -> dispatcher
 * ```
 *
 * Inside a band, middlewares run by ascending priority, then by registration
 * order. There is deliberately no before()/after()/requires() dependency graph.
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
}
