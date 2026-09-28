<?php

declare(strict_types=1);

use Kaly\Router\Routes;
use Kaly\Tests\Mocks\DenyMiddleware;
use Kaly\Tests\Mocks\TraceGroupMiddleware;
use TestModule\Controller\GuardedController;
use TestModule\Controller\ShopController;

return static function (Routes $routes): void {
    $routes->get('/shop/{slug}', [ShopController::class, 'show'])->name('shop.show')->where('slug', '[a-z-]+');

    $routes->post('/shop/{slug}/buy', [ShopController::class, 'buyPost'])->name('shop.buy');

    $routes->group('/api', function (Routes $routes): void {
        $routes->get('/health', [ShopController::class, 'health'])->name('api.health');
    });

    // Defaults are generate-only: every placeholder still matches literally.
    $routes->get('/shop/featured/{tab}', [ShopController::class, 'featured'])->name('shop.featured')->default('tab', 'all');

    // Route middlewares are enforced by the framework
    $routes
        ->prefix('/locked')
        ->middleware(DenyMiddleware::class)
        ->group(function (Routes $routes): void {
            $routes->get('/health', [ShopController::class, 'health'])->name('locked.health');
        });
    $routes
        ->prefix('/guarded')
        ->middleware(TraceGroupMiddleware::class)
        ->group(function (Routes $routes): void {
            $routes->get('/trace', [GuardedController::class, 'index'])->name('guarded.trace');
        });
};
