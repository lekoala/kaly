<?php

declare(strict_types=1);

use Kaly\Core\Module;
use Kaly\Router\Routes;
use RoutableModule\Controller\HelpController;
use RoutableModule\Controller\IndexController;

// All the routing of the module. Routable by convention under
// /routable-module/; the table adds paths convention cannot express, and the
// claim owns /help outside of the module segment. Names are local to the
// module and qualified from outside (routable-module:hello-explicit).
return static function (Module $module): void {
    $module->routes(static function (Routes $routes): void {
        $routes->get('/hello-explicit', [IndexController::class, 'explicit'])->name('hello-explicit');
    });

    $module->claim('/help', static function (Routes $routes): void {
        $routes->get('/', [HelpController::class, 'index'])->name('help');
    });
};
