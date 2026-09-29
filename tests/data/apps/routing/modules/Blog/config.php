<?php

declare(strict_types=1);

use Blog\Controller\NewsController;
use Kaly\Core\Module;
use Kaly\Router\Routes;

return static function (Module $module): void {
    $module->localized()->claim(['fr' => '/actualites', 'en' => '/news'], function (Routes $routes): void {
        $routes->get('/', [NewsController::class, 'index'])->name('news');
    });
};
