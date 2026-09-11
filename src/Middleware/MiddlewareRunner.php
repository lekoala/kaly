<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use Closure;
use InvalidArgumentException;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A stateless PSR-15 middleware stack.
 *
 * The stack is resolved once and can safely handle multiple requests: no
 * per-request state is kept internally.
 */
class MiddlewareRunner implements RequestHandlerInterface
{
    /**
     * @var array<int,array{middleware:class-string|MiddlewareInterface|GeneratorMiddlewareInterface,condition:Closure|null}>
     */
    protected array $config = [];
    protected RequestHandlerInterface $requestHandler;
    protected ?ContainerInterface $container;

    /**
     * @param class-string|callable|RequestHandlerInterface|MiddlewareInterface $requestHandler The final handler of the stack
     * @param ContainerInterface|null $container Used to resolve middleware class strings
     */
    public function __construct(
        string|callable|RequestHandlerInterface|MiddlewareInterface $requestHandler,
        ?ContainerInterface $container = null,
    ) {
        $this->container = $container;
        $this->requestHandler = $this->resolveFinalHandler($requestHandler);
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
     * Checks if the middleware should run based on a closure that gets the request instance and the container if set
     */
    protected function shouldRun(?Closure $condition, ServerRequestInterface $request): bool
    {
        if ($condition) {
            return $condition($request, $this->container) !== false;
        }
        return true;
    }

    /**
     * This is the entry point and represents the entire stack as a single handler.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // We start processing at the first middleware (index 0).
        // If the stack is empty, the final handler is called directly.
        return $this->processNext($request, 0);
    }

    /**
     * This is the core recursive method.
     * It processes the middleware at the given index.
     */
    public function processNext(ServerRequestInterface $request, int $index): ResponseInterface
    {
        // If we've run out of middlewares, execute the final application handler.
        if ($index >= count($this->config)) {
            return $this->requestHandler->handle($request);
        }

        $opt = $this->config[$index];

        // Check if the middleware should run
        if (!$this->shouldRun($opt['condition'], $request)) {
            return $this->processNext($request, $index + 1);
        }

        $middleware = $this->resolveMiddleware($opt['middleware']);

        if ($middleware instanceof GeneratorMiddlewareInterface) {
            // Native middleware using the generator model.
            $generator = $middleware->process($request);

            if (!$generator->valid()) {
                // Short-circuiting generator
                return $generator->getReturn();
            }

            // Get the modified request from the generator's "before" phase.
            $requestAfterBefore = $generator->current();

            // Execute the rest of the stack to get the inner response.
            $responseFromInside = $this->processNext($requestAfterBefore, $index + 1);

            // Now, perform the "after" phase by resuming the generator.
            $generator->send($responseFromInside);
            return $generator->getReturn();
        }

        // Standard PSR-15 middleware: the nested model.
        // The next handler represents "the rest of the stack".
        $nextHandler = new RunNextHandler($this, $index + 1);
        return $middleware->process($request, $nextHandler);
    }

    /**
     * @param class-string $middlewareClass
     */
    public function has(string $middlewareClass): bool
    {
        foreach ($this->config as $middlewareDetails) {
            $middleware = $middlewareDetails['middleware'];
            if (is_object($middleware)) {
                if ($middleware instanceof $middlewareClass) {
                    return true;
                }
            } elseif (is_string($middleware)) {
                if ($middleware === $middlewareClass || is_a($middleware, $middlewareClass, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * @param class-string|MiddlewareInterface|GeneratorMiddlewareInterface $middleware
     */
    public function unshift(string|MiddlewareInterface|GeneratorMiddlewareInterface $middleware, ?Closure $condition = null): self
    {
        array_unshift($this->config, [
            'middleware' => $middleware,
            'condition' => $condition,
        ]);
        return $this;
    }

    /**
     * @param class-string|MiddlewareInterface|GeneratorMiddlewareInterface $middleware
     */
    public function push(string|MiddlewareInterface|GeneratorMiddlewareInterface $middleware, ?Closure $condition = null): self
    {
        $this->config[] = [
            'middleware' => $middleware,
            'condition' => $condition,
        ];
        return $this;
    }
}
