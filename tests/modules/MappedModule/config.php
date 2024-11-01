<?php

/** @var Kaly\Core\Module $this */

use Kaly\Router\ClassRouter;

$this->setNamespace('TestVendor\\MappedModule');

$this->definitions()
    ->callback(ClassRouter::class, function (ClassRouter $router): void {
        $router->addAllowedNamespace("TestVendor\\MappedModule", "MappedModule");
    })
    ->lock();
