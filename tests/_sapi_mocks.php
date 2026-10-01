<?php

declare(strict_types=1);

namespace Kaly\Http {
    use Kaly\Tests\HttpTest;

    function headers_sent(mixed &$filename = null, mixed &$line = null): bool
    {
        HttpTest::$mockResponse[__FUNCTION__] = func_get_args();
        $filename = null;
        $line = null;

        return false;
    }

    function header(string $string, bool $replace = true, int $response_code = 0): void
    {
        HttpTest::$mockResponse[__FUNCTION__] = func_get_args();
    }

    function ob_get_length(): int
    {
        HttpTest::$mockResponse[__FUNCTION__] = func_get_args();

        return 0;
    }
}

namespace Kaly\Core {
    use Kaly\Tests\HttpTest;

    function headers_sent(mixed &$filename = null, mixed &$line = null): bool
    {
        HttpTest::$mockResponse[__FUNCTION__] = func_get_args();
        $filename = null;
        $line = null;

        return false;
    }

    function http_response_code(int $response_code = 0): int|false
    {
        HttpTest::$mockResponse[__FUNCTION__] = func_get_args();

        static $code = 200;
        if (func_num_args() === 0) {
            return $code;
        }
        $previous = $code;
        $code = $response_code;

        return $previous;
    }
}
