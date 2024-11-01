<?php

/** @var Kaly\Core\Module $this */

use Sub\DemoObj;
use Kaly\View\Engine;

// Get's executed before app
$this->setPriority(50);

$this->definitions()
    ->set('some_demo_obj', DemoObj::class)
    ->callback(Engine::class, function (Engine $engine) {
        $engine->setPath('sub', __DIR__ . '/templates');
    })
    ->lock();
