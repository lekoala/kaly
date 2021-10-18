<?php

use Kaly\ClassRouter;

return [
    ClassRouter::class . "->" => [
        function (ClassRouter $router) {
            $router->addAllowedNamespace("MappedModule", "TestVendor\\MappedModule");
        }
    ],
];
