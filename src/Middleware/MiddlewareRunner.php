<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use Closure;
use InvalidArgumentException;
use Kaly\Http\HttpContext;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A stateless PSR-15 middleware stack.
 *
 * The runner only executes one band of an already ordered configuration: the
 * ordering itself belongs to the MiddlewareRegistry. No per-request state is
 * kept internally, so the same runner safely handles many requests.
 *
 * While running, it keeps the HttpContext in sync: the current request is
 * rebound on every step and the middlewares that really entered are marked.
 * The response is not mirrored during the unwind, a middleware already owns
 * the one returned by its own handler.
 */
class MiddlewareRunner implements RequestHandlerInterface
{
    protected RequestHandlerInterface $requestHandler;
    protected MiddlewareRegistry $registry;

    /**
     * @param class-string|callable|RequestHandlerInterface|MiddlewareInterface $requestHandler The final handler of the stack
     * @param ContainerInterface|null $container Used to resolve middleware class strings
     * @param MiddlewareRegistry|null $registry The shared configuration, a private one is created if omitted
     * @param MiddlewareBand $band The band this runner executes
     */
    public function __construct(
        string|callable|RequestHandlerInterface|MiddlewareInterface $requestHandler,
        protected ?ContainerInterface $container = null,
        ?MiddlewareRegistry $registry = null,
        protected MiddlewareBand $band = MiddlewareBand::Incoming,
    ) {
        $this->requestHandler = $this->resolveFinalHandler($requestHandler);
        $this->registry = $registry ?? new MiddlewareRegistry();
    }

    public function getRegistry(): MiddlewareRegistry
    {
        return $this->registry;
    }

    public function getBand(): MiddlewareBand
    {
        return $this->band;
    }

    /**
     * Register a request middleware in the band of this runner.
     *
     * An outgoing middleware cannot run in a request band: use the shared
     * MiddlewareRegistry::add() if you really need to mix families.
     *
     * @param class-string|MiddlewareInterface $middleware
     */
    public function add(string|MiddlewareInterface $middleware, int $priority = 0, ?Closure $when = null): self
    {
        $this->registry->add($this->band, $middleware, $priority, $when);

        return $this;
    }

    /**
     * @param class-string $middlewareClass
     */
    public function has(string $middlewareClass): bool
    {
        return $this->registry->has($middlewareClass, $this->band);
    }

    /**
     * @param class-string|MiddlewareInterface|OutgoingMiddlewareInterface $middleware
     */
    protected function resolveMiddleware(string|MiddlewareInterface|OutgoingMiddlewareInterface $middleware): MiddlewareInterface
    {
        if (is_string($middleware)) {
            if ($this->container === null) {
                throw new LogicException('A container is required to resolve a middleware class string.');
            }
            $middleware = $this->container->get($middleware);
        }
        if ($middleware instanceof MiddlewareInterface) {
            return $middleware;
        }
        if ($middleware instanceof OutgoingMiddlewareInterface) {
            throw new LogicException(sprintf(
                '%s is an outgoing middleware; it cannot run in the %s band.',
                $middleware::class,
                $this->band->value,
            ));
        }
        throw new LogicException('Resolved middleware is of an unknown type.');
    }

    /**
     * @param class-string|callable|RequestHandlerInterface|MiddlewareInterface $handler
     */
    protected function resolveFinalHandler(string|callable|RequestHandlerInterface|MiddlewareInterface $handler): RequestHandlerInterface
    {
        // A class string is resolved like a middleware. A plain function name
        // stays a callable.
        if (is_string($handler) && class_exists($handler)) {
            if ($this->container === null) {
                throw new LogicException('A container is required to resolve a final handler class string.');
            }
            $handler = $this->container->get($handler);
        }
        if ($handler instanceof RequestHandlerInterface) {
            return $handler;
        }
        if (is_callable($handler)) {
            return new CallableToHandlerAdapter($handler);
        }
        if ($handler instanceof MiddlewareInterface) {
            return new MiddlewareToHandlerAdapter($handler);
        }
        throw new InvalidArgumentException(
            'The final handler must be a RequestHandlerInterface, a callable, or a terminating psr-15 MiddlewareInterface.',
        );
    }

    /**
     * Checks if the middleware should run based on a closure that gets the
     * current context and the container if set
     */
    protected function shouldRun(?Closure $condition, HttpContext $ctx): bool
    {
        if ($condition) {
            return $condition($ctx, $this->container) !== false;
        }
        return true;
    }

    /**
     * This is the entry point and represents the entire band as a single handler.
     *
     * The Kernel owns the cycle and binds its context before the pipeline
     * runs; a standalone runner binds a bare context so the stack stays
     * usable outside a kernel cycle.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ctx = HttpContext::tryFrom($request) ?? new HttpContext($request);

        // We start processing at the first middleware (index 0).
        // If the band is empty, the final handler is called directly.
        return $this->processNext($ctx->request(), 0, $ctx);
    }

    /**
     * This is the core recursive method.
     * It processes the middleware at the given index.
     */
    public function processNext(ServerRequestInterface $request, int $index, ?HttpContext $ctx = null): ResponseInterface
    {
        // Always reattach our context: a third party middleware is free to
        // hand over a brand new request object, the cycle must survive it.
        $ctx ??= HttpContext::tryFrom($request) ?? new HttpContext($request);
        $request = $ctx->bind($request);

        $entries = $this->registry->band($this->band);
        $count = count($entries);

        // Walk past the entries whose condition is false. A skipped middleware
        // costs nothing: no stack frame, and never a resolution from the
        // container. Only the next one that really runs recurses.
        while ($index < $count && !$this->shouldRun($entries[$index]->condition, $ctx)) {
            $index++;
        }

        // Once we run out of middlewares, execute the final handler.
        if ($index >= $count) {
            return $this->requestHandler->handle($request);
        }

        $middleware = $this->resolveMiddleware($entries[$index]->middleware);

        // The context tracks what really entered, not what was configured
        $ctx->markMiddleware($middleware::class);

        // PSR-15 middleware: the nested model. The next handler represents
        // the rest of the band: before/after, local state, catch and finally
        // all compose naturally around $handler->handle().
        $nextHandler = new RunNextHandler($this, $index + 1, $ctx);
        return $middleware->process($request, $nextHandler);
    }
}
