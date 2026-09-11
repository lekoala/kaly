<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use Closure;
use Generator;
use InvalidArgumentException;
use Kaly\Core\HttpContext;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

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
     * Register a middleware in the band of this runner
     *
     * @param class-string|MiddlewareInterface|GeneratorMiddlewareInterface $middleware
     */
    public function add(string|MiddlewareInterface|GeneratorMiddlewareInterface $middleware, int $priority = 0, ?Closure $when = null): self
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
     * @param class-string|MiddlewareInterface|GeneratorMiddlewareInterface $middleware
     */
    protected function resolveMiddleware(string|MiddlewareInterface|GeneratorMiddlewareInterface $middleware): MiddlewareInterface|GeneratorMiddlewareInterface
    {
        if (is_string($middleware)) {
            if ($this->container === null) {
                throw new LogicException('A container is required to resolve a middleware class string.');
            }
            $middleware = $this->container->get($middleware);
        }
        if ($middleware instanceof MiddlewareInterface || $middleware instanceof GeneratorMiddlewareInterface) {
            return $middleware;
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
     * The protocol of a generator middleware is deliberately narrow: it yields
     * the request at most once and returns a response. Anything else is a
     * programming error and must not surface as a cryptic generator error.
     *
     * @param Generator<int,mixed,ResponseInterface,mixed> $generator
     */
    protected function generatorResponse(Generator $generator, GeneratorMiddlewareInterface $middleware): ResponseInterface
    {
        if ($generator->valid()) {
            throw new LogicException(sprintf('A generator middleware must yield at most once, %s yielded again.', $middleware::class));
        }

        $response = $generator->getReturn();
        if (!$response instanceof ResponseInterface) {
            throw new LogicException(sprintf(
                'A generator middleware must return a %s, %s returned %s.',
                ResponseInterface::class,
                $middleware::class,
                get_debug_type($response),
            ));
        }

        return $response;
    }

    /**
     * This is the entry point and represents the entire band as a single handler.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ctx = HttpContext::ensure($request);

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
        $ctx ??= HttpContext::ensure($request);
        $request = $ctx->bind($request);

        $entries = $this->registry->band($this->band);

        // Once we run out of middlewares, execute the final handler.
        if ($index >= count($entries)) {
            return $this->requestHandler->handle($request);
        }

        $entry = $entries[$index];

        // Check if the middleware should run
        if (!$this->shouldRun($entry->condition, $ctx)) {
            return $this->processNext($request, $index + 1, $ctx);
        }

        $middleware = $this->resolveMiddleware($entry->middleware);

        // The context tracks what really entered, not what was configured
        $ctx->markMiddleware($middleware::class);

        if ($middleware instanceof GeneratorMiddlewareInterface) {
            // Native middleware using the generator model.
            $generator = $middleware->process($request);

            if (!$generator->valid()) {
                // Short-circuiting generator: it never yielded
                return $this->generatorResponse($generator, $middleware);
            }

            // Get the modified request from the before phase of the generator.
            $requestAfterBefore = $generator->current();
            if (!$requestAfterBefore instanceof ServerRequestInterface) {
                throw new LogicException(sprintf(
                    'A generator middleware must yield a %s, %s yielded %s.',
                    ServerRequestInterface::class,
                    $middleware::class,
                    get_debug_type($requestAfterBefore),
                ));
            }

            try {
                // Execute the rest of the stack to get the inner response.
                $responseFromInside = $this->processNext($requestAfterBefore, $index + 1, $ctx);
            } catch (Throwable $e) {
                // Resume at the yield with the failure so that a middleware can
                // catch it. If it does not, the exception simply keeps going up.
                $generator->throw($e);
                return $this->generatorResponse($generator, $middleware);
            }

            // Now, perform the after phase by resuming the generator.
            $generator->send($responseFromInside);
            return $this->generatorResponse($generator, $middleware);
        }

        // Standard PSR-15 middleware: the nested model.
        // The next handler represents the rest of the band.
        $nextHandler = new RunNextHandler($this, $index + 1, $ctx);
        return $middleware->process($request, $nextHandler);
    }
}
