<?php

/** @var Kaly\Core\Module $this */

use Kaly\Log\FileLogger;
use Kaly\Router\ClassRouter;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Tests\Mocks\TestObject;

$value_is_not_leaked = 'test';

$this
    ->definitions()
    ->bind(TestInterface::class, TestObject::class)
    ->callback(ClassRouter::class, function (ClassRouter $router): void {
        $router->setAllowedLocales(['en', 'fr'], ['LangModule']);
        $router->addAllowedNamespace('TestModule');
    })
    ->set(Kaly\Core\App::DEBUG_LOGGER, function (): FileLogger {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = substr((string) $script, 0, strpos((string) $script, 'vendor' . DIRECTORY_SEPARATOR . 'bin'));
        return new FileLogger("{$basePath}/tests.log");
    })
    ->lock();
