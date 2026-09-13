<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use Closure;
use Kaly\Core\HttpContext;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;

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
 */
final class OutgoingRunner
{
    protected MiddlewareRegistry $registry;

    /**
     * @param ContainerInterface|null $container Used to resolve outgoing middleware class strings
     * @param MiddlewareRegistry|null $registry The shared configuration, a private one is created if omitted
     */
    public function __construct(
        protected ?ContainerInterface $container = null,
        ?MiddlewareRegistry $registry = null,
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
    public function add(string|OutgoingMiddlewareInterface $middleware, int $priority = 0, ?Closure $when = null): self
    {
        $this->registry->add(MiddlewareBand::Outgoing, $middleware, $priority, $when);

        return $this;
    }

    /**
     * @param class-string|MiddlewareInterface|GeneratorMiddlewareInterface|OutgoingMiddlewareInterface $middleware
     */
    protected function resolveMiddleware(string|MiddlewareInterface|GeneratorMiddlewareInterface|OutgoingMiddlewareInterface $middleware): OutgoingMiddlewareInterface
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
        if ($middleware instanceof MiddlewareInterface || $middleware instanceof GeneratorMiddlewareInterface) {
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
     */
    public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
    {
        $current = $response;

        foreach ($this->registry->band(MiddlewareBand::Outgoing) as $entry) {
            if (!$this->shouldRun($entry->condition, $current, $ctx)) {
                continue;
            }

            $middleware = $this->resolveMiddleware($entry->middleware);
            $ctx->markMiddleware($middleware::class);
            $current = $middleware->process($current, $ctx);
        }

        return $current;
    }
}
