<?php

declare(strict_types=1);

namespace App;

use App\Controller\PageController;
use Kaly\Router\ResolverInterface;
use Kaly\Router\Route;
use Kaly\Router\RouteRequest;

/**
 * A ModelAsController style resolver: the page tree decides, at runtime,
 * whether it knows the url
 */
final class PageResolver implements ResolverInterface
{
    private const PAGES = [
        'company' => 'Company',
        'company/team' => 'Team',
    ];

    public function resolve(RouteRequest $request): ?Route
    {
        // The deepest known page wins, the remaining segments are its action
        for ($depth = count($request->segments); $depth > 0; $depth--) {
            $path = implode('/', array_slice($request->segments, 0, $depth));
            if (!isset(self::PAGES[$path])) {
                continue;
            }
            $action = $request->segments[$depth] ?? 'index';
            if (!method_exists(PageController::class, $action)) {
                return null;
            }
            return Route::to(PageController::class, $action, bindings: ['page' => new Page(self::PAGES[$path])]);
        }
        return null;
    }
}
