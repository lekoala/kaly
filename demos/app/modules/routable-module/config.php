<?php

declare(strict_types=1);

use Kaly\Core\Module;
use Kaly\Di\Definitions;

// The routing of this module lives in routes.php (a table and a claim):
// config.php requires it explicitly and only declares services.
return static function (Module $module, Definitions $di): void {
    /** @var Closure(Module): void $routes */
    $routes = require __DIR__ . '/routes.php';
    $routes($module);
};
