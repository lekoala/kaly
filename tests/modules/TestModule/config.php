<?php

declare(strict_types=1);

use Kaly\Core\App;
use Kaly\Core\Module;
use Kaly\Di\Definitions;
use Kaly\Log\FileLogger;
use Kaly\Router\ClassRouter;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tpl\ViewEngine;
use Kaly\View\Adapter\KalyTplRenderer;
use Kaly\View\RendererInterface;

$value_is_not_leaked = 'test';

// PSR-17 factories are discovered (nyholm/psr7 is installed)
return static function (Module $module, Definitions $di): void {
    $di
        ->bind(TestInterface::class, TestObject::class)
        ->set(RendererInterface::class, new KalyTplRenderer(new ViewEngine(__DIR__ . '/templates')))
        ->callback(ClassRouter::class, function (ClassRouter $router): void {
            $router->setAllowedLocales(['en', 'fr'], ['LangModule']);
        })
        ->set(App::DEBUG_LOGGER, function (): FileLogger {
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            $basePath = substr((string) $script, 0, strpos((string) $script, 'vendor' . DIRECTORY_SEPARATOR . 'bin'));
            return new FileLogger("{$basePath}/tests.log");
        });
};
