<?php

use Kaly\Logger;
use Kaly\ClassRouter;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Translator;

$value_is_not_leaked = "test";

return [
    TestInterface::class => function () {
        return new TestObject();
    },
    ClassRouter::class . "->" => [
        function (ClassRouter $router) {
            $router->setAllowedLocales(["en", "fr"], ["LangModule"]);
            $router->addAllowedNamespace("TestModule");
        }
    ],
    // Register a dedicated logger for tests
    Kaly\App::DEBUG_LOGGER => function (): Logger {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = substr($script, 0, strpos($script, 'vendor' . DIRECTORY_SEPARATOR . 'bin'));
        return new Logger("$basePath/tests.log");
    }
];
