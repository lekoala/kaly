<?php

declare(strict_types=1);

use Kaly\Core\Module;
use Kaly\Router\Routes;
use RoutableModule\Controller\IndexController;

// Routable by convention under /routable-module/ without any configuration.
// An explicit route table adds paths convention cannot express; names are
// local to the module and qualified from outside (routable-module:hello-explicit).
return static function (Module $module): void {
    $module->routes(function (Routes $routes): void {
        $routes->get('/hello-explicit', [IndexController::class, 'explicit'])->name('hello-explicit');
    });
};
