<?php

// Flexible vendor location
$dir = dirname(__DIR__);
while ($dir && !is_file($dir . '/vendor/autoload.php')) {
    $dir = dirname($dir);
}
require_once $dir . '/vendor/autoload.php';

// Mock classes
// require_once __DIR__ . '/../src/_functions/global.php';
foreach (glob(__DIR__ . '/mocks/*.php') as $f) {
    require_once $f;
}
// Modules
// require_once __DIR__ . "/modules/TestModule/src/Controller/IndexController.php";
// require_once __DIR__ . "/modules/TestModule/src/Controller/DemoController.php";
// require_once __DIR__ . "/modules/TestModule/src/Controller/JsonController.php";
// require_once __DIR__ . "/modules/LangModule/src/Controller/IndexController.php";
// require_once __DIR__ . "/modules/MappedModule/src/Controller/IndexController.php";

// Mock functions that will get called instead of regular php function due to namespace
require_once __DIR__ . "/_functions.php";
