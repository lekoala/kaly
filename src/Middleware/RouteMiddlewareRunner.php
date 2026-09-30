<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use Kaly\Http\HttpContext;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs the middlewares scoped to the matched route, right before the
 * controller:
 *
 * ```text
 * incoming -> routing -> routed -> [route middlewares] -> dispatcher
 * ```
 *
 * They come from the route tables (`->middleware()` on a route or a group) and
 * from `#[Middleware]` on the controller. They behave exactly like a routed
 * middleware: resolved from the container and marked on the context.
 */
final class RouteMiddlewareRunner implements RequestHandlerInterface
{
    /**
     * One stateless runner per distinct middleware list, built on first use
     *
     * @var array<string,MiddlewareRunner>
     */
    private array $runners = [];

    public function __construct(
        private RequestHandlerInterface $handler,
        private ?ContainerInterface $container = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $middlewares = HttpContext::from($request)->route()->middlewares;
        if ($middlewares === []) {
            return $this->handler->handle($request);
        }

        return $this->runnerFor($middlewares)->handle($request);
    }

    /**
     * @param list<class-string> $middlewares
     */
    private function runnerFor(array $middlewares): MiddlewareRunner
    {
        $key = implode('|', $middlewares);
        if (isset($this->runners[$key])) {
            return $this->runners[$key];
        }

        $registry = new MiddlewareRegistry();
        foreach ($middlewares as $middleware) {
            $registry->routed($middleware);
        }

        return $this->runners[$key] = new MiddlewareRunner($this->handler, $this->container, $registry, MiddlewareBand::Routed);
    }
}
