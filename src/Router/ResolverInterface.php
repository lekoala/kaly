<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Http\MethodNotAllowedException;
use Kaly\Http\RedirectException;

/**
 * Resolves the url of a module, below its entry point.
 *
 * A module holds a list of resolvers ordered by priority (lowest first, then
 * registration order): its local route table, the convention, and any custom
 * resolver, like pages stored in a database. The first one returning a route
 * wins; null means "not mine" and hands over to the next one. This is what
 * allows dynamic resolution:
 *
 * ```php
 * final class PageResolver implements ResolverInterface
 * {
 *     public function resolve(RouteRequest $request): ?Route
 *     {
 *         $page = $this->pages->findByPath($request->segments);
 *         if ($page === null) {
 *             return null;
 *         }
 *         // The page reaches the controller constructor: PageController(Page $page)
 *         return Route::to($page->controllerClass(), 'index', bindings: ['page' => $page]);
 *     }
 * }
 * ```
 *
 * Resolvers are shared services: keep them free of per-request state.
 */
interface ResolverInterface
{
    /**
     * Return null for an url the resolver does not know, or throw a
     * RouteNotFoundException to also say why (shown on the 404 debug page).
     * Either way, the next resolver of the module gets its chance.
     *
     * @throws RouteNotFoundException Not mine, with a reason
     * @throws MethodNotAllowedException When the path is known for other methods only (authoritative:
     * the resolvers that follow never get a chance to reinterpret it)
     * @throws RedirectException To enforce a canonical url
     */
    public function resolve(RouteRequest $request): ?Route;
}
