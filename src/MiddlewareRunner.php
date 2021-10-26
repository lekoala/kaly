<?php

declare(strict_types=1);

namespace Kaly;

use Closure;
use Exception;
use RuntimeException;
use ReflectionFunction;
use ReflectionNamedType;
use Middlewares\Utils\RequestHandler;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;

class MiddlewareRunner extends RequestHandler
{
    protected App $app;
    protected bool $hasErrorHandler = false;
    /**
     * @var array<mixed>
     */
    protected array $middlewares = [];
    protected bool $linear = false;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param class-string|MiddlewareInterface $middleware
     */
    protected function resolveMiddleware($middleware): MiddlewareInterface
    {
        $di = $this->app->getDi();
        if (is_string($middleware)) {
            $middlewareName = (string)$middleware;
            if (!$di->has($middlewareName)) {
                throw new RuntimeException("Invalid middleware definition '$middlewareName'");
            }
            /** @var MiddlewareInterface $middleware  */
            $middleware = $di->get($middlewareName);
        }
        return $middleware;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $di = $this->app->getDi();

        // We need to set the request in case we use it in our middleware conditions
        /** @var State $state */
        $state = $di->get(State::class);
        $state->setRequest($request);

        // We can return early
        if ($this->linear) {
            $this->linear = false;
            return Http::respond();
        }

        // Yeah what's a good project without a goto
        start:

        // Keep this into handle function to avoid spamming the stack with method calls
        $opts = current($this->middlewares);
        next($this->middlewares);

        if ($opts) {
            /** @var Closure|null $condition  */
            $condition = $opts['condition'];
            if ($condition) {
                $result = (bool)$this->app->inject($condition);
                // Skip this and handle next
                if (!$result) {
                    return $this->handle($request);
                }
            }

            /** @var class-string|MiddlewareInterface $handler  */
            $handler = $opts['middleware'];
            $middleware = $this->resolveMiddleware($handler);

            $linear = boolval($opts['linear'] ?? false);
            // Linear middlewares only update the request and are not nested into the stack
            if ($linear) {
                // Set the linear flag so that we will only care about the updated request
                $this->linear = true;
                $response = $middleware->process($request, $this);
                // Use updated request reference
                $request = $state->getRequest();
                // Use goto to avoid stack trace
                goto start;
            } else {
                return $middleware->process($request, $this);
            }
        }

        // Reset so that next incoming request will run through all the middlewares
        reset($this->middlewares);

        return $this->app->process($request, $this);
    }

    /**
     * @param class-string|MiddlewareInterface $middleware
     */
    public function addMiddleware($middleware, Closure $condition = null, bool $linear = false): self
    {
        if ($this->app->getBooted()) {
            throw new RuntimeException("Cannot add middlewares once booted");
        }
        $this->middlewares[] = [
            'middleware' => $middleware,
            'condition' => $condition,
            'linear' => $linear,
        ];
        return $this;
    }

    /**
     * This should probably be called before any other middleware
     * It will not be set as linear as it needs to wrap calls to catch errors
     * @param class-string|MiddlewareInterface $middleware
     */
    public function addErrorHandler($middleware, Closure $condition = null): self
    {
        if ($this->hasErrorHandler) {
            throw new RuntimeException("Error handler already set");
        }
        $this->hasErrorHandler = true;
        return $this->addMiddleware($middleware, $condition, false);
    }
}
