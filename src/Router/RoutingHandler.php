<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Http\HttpContext;
use Kaly\Text\LocaleResolver;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The fixed routing step of a kaly request.
 *
 * This is not a configurable middleware but a structural stage of the
 * framework: it splits the pipeline in two bands and guarantees that anything
 * registered as "routed" already knows the route and the locale.
 *
 * ```text
 * incoming
 * ---------
 * ROUTING
 * ---------
 * routed
 * ---------
 * DISPATCH
 * ```
 */
final class RoutingHandler implements RequestHandlerInterface
{
    public function __construct(
        private RouterInterface $router,
        private LocaleResolver $localeResolver,
        private RequestHandlerInterface $next,
    ) {
        // promoted
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ctx = HttpContext::ensure($request);

        $route = $this->router->match($ctx->request);
        $ctx->route = $route;

        // The route wins over a locale imposed by an incoming middleware,
        // which in turn wins over content negotiation.
        $ctx->locale = $this->localeResolver->resolve($ctx->request, $route->locale ?? $ctx->locale);

        return $this->next->handle($ctx->request);
    }
}
