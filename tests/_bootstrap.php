<?php

// Flexible vendor location
$dir = dirname(__DIR__);
while ($dir && !is_file($dir . '/vendor/autoload.php')) {
    $dir = dirname($dir);
}
require_once $dir . '/vendor/autoload.php';

require_once __DIR__ . '/../src/_functions/global.php';
require_once __DIR__ . '/mocks/TestApp.php';
require_once __DIR__ . '/mocks/TestMiddleware.php';
require_once __DIR__ . '/mocks/TestInterface.php';
require_once __DIR__ . '/mocks/TestObject.php';
require_once __DIR__ . '/mocks/TestObject2.php';
require_once __DIR__ . '/mocks/TestObject3.php';
require_once __DIR__ . '/mocks/TestObject4.php';
require_once __DIR__ . "/modules/TestModule/src/Controller/IndexController.php";
require_once __DIR__ . "/modules/TestModule/src/Controller/DemoController.php";
require_once __DIR__ . "/modules/TestModule/src/Controller/JsonController.php";
require_once __DIR__ . "/modules/LangModule/src/Controller/IndexController.php";
require_once __DIR__ . "/modules/MappedModule/src/Controller/IndexController.php";

// Mock functions that will get called instead of regular php function due to namespace
require_once __DIR__ . "/_functions.php";
