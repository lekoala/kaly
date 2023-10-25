<?php

declare(strict_types=1);

namespace Kaly;

use Kaly\Tests\HttpTest;

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
