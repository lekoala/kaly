<?php

/** @var Kaly\Core\Module $this */

use Kaly\Router\ClassRouter;

$this->definitions()
    ->callback(ClassRouter::class, function (ClassRouter $router): void {
        $router->addAllowedNamespace("LangModule");
    })
    ->lock();
