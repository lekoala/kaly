<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\I18n\LocaleResolver;
use Kaly\Router\RouterInterface;
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
        // The Kernel binds the cycle context before the pipeline runs; a
        // standalone handler binds a bare context instead, so downstream
        // stages recover the same cycle through from().
        $ctx = HttpContext::tryFrom($request) ?? new HttpContext($request);
        $request = $ctx->bind($request);

        $ctx->useRouter($this->router);
        $route = $this->router->match($ctx->request());
        $ctx->useRoute($route);

        // The route wins over a locale imposed by an incoming middleware,
        // which in turn wins over content negotiation.
        $imposed = $ctx->hasLocale() ? $ctx->locale() : null;
        $ctx->useLocale($this->localeResolver->resolve($ctx->request(), $route->locale ?? $imposed));

        return $this->next->handle($ctx->request());
    }
}
