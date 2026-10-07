<?php

declare(strict_types=1);

use Kaly\Core\App;
use Kaly\Core\Module;
use Kaly\Di\Definitions;
use Kaly\Log\FileLogger;
use Kaly\Tpl\ViewEngine;
use Kaly\View\Adapter\KalyTplRenderer;
use Kaly\View\RendererInterface;

// Main module: mounted on '/', it answers without url prefix.
// PSR-17 factories are discovered from the installed PSR-7 implementation.
return static function (Module $module, Definitions $di): void {
    $module->mount('/');

    $di->set(RendererInterface::class, new KalyTplRenderer(new ViewEngine(__DIR__ . '/templates')))->set(
        App::DEBUG_LOGGER,
        fn() => new FileLogger(dirname(__DIR__, 2) . '/temp/logs/debug.log'),
    );
};
