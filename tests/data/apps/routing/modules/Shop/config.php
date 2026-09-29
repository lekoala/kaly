<?php

declare(strict_types=1);

use Kaly\Core\Module;
use Kaly\Router\Routes;
use Shop\Controller\ProductController;

return static function (Module $module): void {
    $module
        ->mount(['fr' => 'boutique', 'en' => 'shop'])
        ->localized()
        ->routes(function (Routes $routes): void {
            $routes->get(['fr' => '/produit/{slug}', 'en' => '/product/{slug}'], [ProductController::class, 'show'])->name('product');
        });
};
