<?php

declare(strict_types=1);

namespace Kaly;

use Kaly\Tests\HttpTest;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/mocks/TestInterface.php';
require_once __DIR__ . '/mocks/TestObject.php';
require_once __DIR__ . '/mocks/TestObject2.php';
require_once __DIR__ . '/mocks/TestObject3.php';
require_once __DIR__ . "/modules/TestModule/src/Controller/IndexController.php";

// Mock functions that will get called instead of regular php function due to namespace

function headers_sent()
{
    HttpTest::$mockResponse[__FUNCTION__] = func_get_args();
    return false;
}

function header(string $string, bool $replace = true, int $http_response_code = null): void
{
    HttpTest::$mockResponse[__FUNCTION__] = func_get_args();
}

function ob_get_length()
{
    HttpTest::$mockResponse[__FUNCTION__] = func_get_args();
    return 0;
}
