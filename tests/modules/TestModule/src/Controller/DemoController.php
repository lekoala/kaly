<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Core\AbstractController;
use Psr\Http\Message\ServerRequestInterface;

class DemoController extends AbstractController
{
    public function index($param = ""): string
    {
        if ($param) {
            return "hello $param";
        }
        return "hello demo";
    }

    public function methodGet(): string
    {
        return 'get';
    }

    public function methodPost(): string
    {
        return 'post';
    }

    public function func(): string
    {
        return "hello func";
    }

    //@codingStandardsIgnoreLine
    public function hello_func(): string
    {
        return "hello underscore";
    }

    public function arr(...$args): string
    {
        return "hello " . implode(",", $args);
    }

    public function arrplus($test, ...$args): string
    {
        return "hello " . $test . ',' . implode(",", $args);
    }
}
