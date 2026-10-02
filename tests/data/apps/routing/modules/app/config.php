<?php

declare(strict_types=1);

use App\Controller\ContactController;
use App\PageResolver;
use Kaly\Core\Module;
use Kaly\Router\Routes;

// The root module: mounted on '/', it answers without url prefix and its
// route names need no qualifier
return static function (Module $module): void {
    $module
        ->mount('/')
        ->localized()
        ->routes(function (Routes $routes): void {
            $routes->get(['fr' => '/a-propos', 'en' => '/about'], [ContactController::class, 'index'])->name('contact');
        })
        // Pages stored "in a database", between the table and the convention
        ->resolver(PageResolver::class, priority: 500);
};
