<?php

use Kaly\Core\App;
use Kaly\Middleware\Builtin\FileServer;
use Kaly\Middleware\Builtin\PreventFileAccess;

// The demo runs on the framework repository dependencies
require dirname(__DIR__, 3) . '/vendor/autoload.php';

$app = App::create(dirname(__DIR__));
$app->middleware()
    ->incoming(FileServer::class, priority: 100)
    ->incoming(PreventFileAccess::class);

$app->run();
