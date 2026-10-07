<?php

declare(strict_types=1);

use Kaly\Core\App;
use Kaly\Http\Middleware\FileServer;
use Kaly\Http\Middleware\PreventSensitivePathAccess;

// The demo runs on the framework repository dependencies
require dirname(__DIR__, 3) . '/vendor/autoload.php';

$app = App::default(dirname(__DIR__));
$app->middleware()->incoming(FileServer::class, priority: -100)->incoming(PreventSensitivePathAccess::class);

$app->run();
