<?php

/** @var Kaly\Core\Module $this */

use Sub\DemoObj;

// Get's executed before app
$this->setPriority(50);

$this->definitions()
    ->set('some_demo_obj', DemoObj::class)
    ->lock();
