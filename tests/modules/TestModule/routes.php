<?php

declare(strict_types=1);

use Kaly\Router\Routes;
use TestModule\Controller\ShopController;

return static function (Routes $routes): void {
    $routes->get('/shop/{slug}', [ShopController::class, 'show'])->name('shop.show')->where('slug', '[a-z-]+');

    $routes->post('/shop/{slug}/buy', [ShopController::class, 'buyPost'])->name('shop.buy');

    $routes->group('/api', function (Routes $routes): void {
        $routes->get('/health', [ShopController::class, 'health'])->name('api.health');
    });

    // Defaults are generate-only: every placeholder still matches literally.
    $routes->get('/shop/featured/{tab}', [ShopController::class, 'featured'])->name('shop.featured')->default('tab', 'all');
};
