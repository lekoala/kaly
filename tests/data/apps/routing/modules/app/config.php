<?php

declare(strict_types=1);

use App\Controller\ContactController;
use App\PageResolver;
use Kaly\Core\Module;
use Kaly\Router\Routes;

// The default module: no url prefix, route names without prefix
return static function (Module $module): void {
    $module
        ->localized()
        ->routes(function (Routes $routes): void {
            $routes->get(['fr' => '/a-propos', 'en' => '/about'], [ContactController::class, 'index'])->name('contact');
        })
        // Pages stored "in a database", between the table and the convention
        ->resolver(PageResolver::class, priority: 500);
};
