<?php

use Kaly\Core\Module;
use Kaly\Di\Definitions;
use Sub\DemoObj;

return static function (Module $module, Definitions $di): void {
    // Configured before app
    $module->priority(50)->withoutConventionRouting();

    $di->set('some_demo_obj', DemoObj::class);
};
