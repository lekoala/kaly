<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use Closure;
use Kaly\Core\HttpContext;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Throwable;

/**
 * The outgoing phase of a request, run once the response exists.
 *
 * Unlike the request runners, this is not a PSR-15 stack: the response is
 * already there, whatever its origin (happy path, short-circuit, kernel-built
 * error response). Each outgoing middleware is a pure Response -> Response
 * transformation, applied by ascending priority, then by registration order.
 *
 * A condition receives the current response, so a later outgoing middleware
 * can decide on the response produced by an earlier one:
 *
 * ```php
 * $app->middleware()->outgoing(
 *     WebpResponse::class,
 *     when: static fn(ResponseInterface $response): bool => $response->getStatusCode() === 200,
 * );
 * ```
 *
 * The runner is stateless and marks every middleware that really entered, so
 * the executed trace is available as on the request bands.
 *
 * The phase is attempted at most once per response: if an outgoing middleware
 * throws, the phase stops and the whole previous transformation is replaced by
 * the error response built by the kernel. The kernel then recovers: only the
 * `always` middlewares run on that error response.
 *
 * An `always` middleware never breaks a response: if it throws, the failure
 * is reported and the response it received goes on unchanged.
 */
final class OutgoingRunner
{
    protected MiddlewareRegistry $registry;

    /**
     * @param ContainerInterface|null $container Used to resolve outgoing middleware class strings
     * @param MiddlewareRegistry|null $registry The shared configuration, a private one is created if omitted
     * @param (Closure(\Throwable, HttpContext): void)|null $report Receives the failures of `always` middlewares
     */
    public function __construct(
        protected ?ContainerInterface $container = null,
        ?MiddlewareRegistry $registry = null,
        protected ?Closure $report = null,
    ) {
        $this->registry = $registry ?? new MiddlewareRegistry();
    }

    public function getRegistry(): MiddlewareRegistry
    {
        return $this->registry;
    }

    /**
     * Register an outgoing middleware in the shared configuration
     *
     * @param class-string|OutgoingMiddlewareInterface $middleware
     * @param Closure|null $when Receives the current response, the context and the container; returning false skips the middleware
     */
    public function add(
        string|OutgoingMiddlewareInterface|Closure $middleware,
        int $priority = 0,
        ?Closure $when = null,
        bool $always = false,
    ): self {
        $this->registry->outgoing($middleware, $priority, $when, $always);

        return $this;
    }

    /**
     * @param class-string|MiddlewareInterface|OutgoingMiddlewareInterface $middleware
     */
    protected function resolveMiddleware(string|MiddlewareInterface|OutgoingMiddlewareInterface $middleware): OutgoingMiddlewareInterface
    {
        if (is_string($middleware)) {
            if ($this->container === null) {
                throw new LogicException('A container is required to resolve an outgoing middleware class string.');
            }
            $middleware = $this->container->get($middleware);
        }
        if ($middleware instanceof OutgoingMiddlewareInterface) {
            return $middleware;
        }
        if ($middleware instanceof MiddlewareInterface) {
            throw new LogicException(sprintf('%s is a request middleware; it cannot run in the outgoing band.', $middleware::class));
        }
        throw new LogicException('Resolved outgoing middleware is of an unknown type.');
    }

    /**
     * Checks if the outgoing middleware should run based on a closure that
     * receives the current response, the context and the container
     */
    protected function shouldRun(?Closure $condition, ResponseInterface $response, HttpContext $ctx): bool
    {
        if ($condition) {
            return $condition($response, $ctx, $this->container) !== false;
        }
        return true;
    }

    /**
     * Apply every eligible outgoing middleware, in order.
     *
     * Each middleware receives the response produced so far, so the band can
     * build on its own previous transformations.
     *
     * @param bool $recovering Only run the `always` middlewares (the kernel
     *   recovers from a failed phase)
     */
    public function process(ResponseInterface $response, HttpContext $ctx, bool $recovering = false): ResponseInterface
    {
        $current = $response;

        foreach ($this->registry->band(MiddlewareBand::Outgoing) as $entry) {
            if ($recovering && !$entry->always) {
                continue;
            }
            if (!$entry->always) {
                $current = $this->step($entry, $current, $ctx);
                continue;
            }
            try {
                $current = $this->step($entry, $current, $ctx);
            } catch (Throwable $ex) {
                // A guarantee never breaks the response it was given
                if ($this->report !== null) {
                    ($this->report)($ex, $ctx);
                }
            }
        }

        return $current;
    }

    private function step(MiddlewareEntry $entry, ResponseInterface $response, HttpContext $ctx): ResponseInterface
    {
        if (!$this->shouldRun($entry->condition, $response, $ctx)) {
            return $response;
        }

        $middleware = $this->resolveMiddleware($entry->middleware);
        $ctx->markMiddleware($middleware::class);

        return $middleware->process($response, $ctx);
    }
}
