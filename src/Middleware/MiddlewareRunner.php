<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use Closure;
use Kaly\Di\Injector;
use Kaly\Http\ResponseException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MiddlewareRunner implements RequestHandlerInterface
{
    /**
     * @var array<array{middleware:class-string|MiddlewareInterface,condition:Closure|null,linear:bool}>
     */
    protected array $middlewares = [];
    protected ResponseFactoryInterface $factory;
    protected ?ServerRequestInterface $request = null;
    protected Injector $injector;
    private bool $linear = false;

    public function __construct(Injector $injector)
    {
        $this->injector = $injector;
        $this->factory = $this->injector->make(ResponseFactoryInterface::class);
    }

    /**
     * @param class-string|MiddlewareInterface $middleware
     */
    protected function resolveMiddleware($middleware): MiddlewareInterface
    {
        if (is_string($middleware)) {
            $middleware = $this->injector->make($middleware);
        }
        return $middleware;
    }

    protected function shouldSkip(?Closure $condition, ServerRequestInterface $request): bool
    {
        if ($condition) {
            // If the condition returns false, it means we should skip
            return !$this->injector->invoke($condition, request: $request);
        }
        return false;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Keep a request reference
        $this->request = $request;

        // handle() is called back in the goto loop,
        if ($this->linear) {
            // Return a dummy response to comply with interface
            return $this->factory->createResponse();
        }

        assert(!empty($this->middlewares));

        $response = null;

        // Yeah what's a good project without a goto
        start:

        try {
            // Keep this into handle function to avoid spamming the stack with method calls
            $opts = current($this->middlewares);
            next($this->middlewares);

            if ($opts) {
                if ($this->shouldSkip($opts['condition'], $this->request)) {
                    // Skip this and handle next
                    return $this->handle($this->request);
                }

                // Resolve middleware
                $middleware = $this->resolveMiddleware($opts['middleware']);
                $linear = $opts['linear'];

                // Linear middlewares only update the request and are not nested into the stack
                // Response is discarded
                if ($linear) {
                    // Set the linear flag so that we will only care about the updated request
                    $this->linear = true;
                    $middleware->process($this->request, $this);
                    // Process will call MiddlewareRunner::handle and request reference will be updated
                    $this->linear = false;
                    // Use goto to avoid stack trace
                    goto start;
                } else {
                    return $middleware->process($this->request, $this);
                }
            }
        } catch (SkipMiddlewareException) {
            // Skip this and handle next
            $this->linear = false;
            return $this->handle($this->request);
        } catch (ResponseException $e) {
            // Maybe the middleware wants to prevent other to execute
            $response = $e->getResponse();
        }

        // We may never reach this part if the middleware did not call back this

        // No responses provided by our middlewares
        if ($response === null) {
            $response = $this->factory->createResponse(404);
        }
        return $response;
    }

    /**
     * Automatically calls reset before handling the request
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handleNewRequest(ServerRequestInterface $request): ResponseInterface
    {
        $this->reset();
        return $this->handle($request);
    }

    /**
     * Reset the dispatcher and make sure we will run all the middlewares again
     * This should be called before calling handle()
     * @return void
     */
    public function reset(): void
    {
        // Reset so that next incoming request will run through all the middlewares
        reset($this->middlewares);
        // All middlewares have been processed
        $this->request = null;
    }

    public function has(string $middlewareClass): bool
    {
        foreach ($this->middlewares as $middlewareDetails) {
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
     * @param class-string|MiddlewareInterface $middleware
     */
    public function unshift($middleware, ?Closure $condition = null, bool $linear = false): self
    {
        array_unshift($this->middlewares, [
            'middleware' => $middleware,
            'condition' => $condition,
            'linear' => $linear,
        ]);
        return $this;
    }

    /**
     * @param class-string|MiddlewareInterface $middleware
     * @param ?Closure $condition
     * @param bool $linear Pass true to avoid nesting the middleware in the stack. Ignores the response.
     */
    public function push($middleware, ?Closure $condition = null, bool $linear = false): self
    {
        $this->middlewares[] = [
            'middleware' => $middleware,
            'condition' => $condition,
            'linear' => $linear,
        ];
        return $this;
    }
}
