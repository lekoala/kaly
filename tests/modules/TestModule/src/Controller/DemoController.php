<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Core\AbstractController;

class DemoController extends AbstractController
{
    public function index(string $param = ''): string
    {
        if ($param) {
            return "hello {$param}";
        }
        return 'hello demo';
    }

    public function indexPost(): string
    {
        return 'demo posted';
    }

    public function lastPostGet(): string
    {
        return 'last post';
    }

    public function moveItemPost(int $id): string
    {
        return "moved {$id}";
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
        return 'hello func';
    }

    //@codingStandardsIgnoreLine
    public function hello_func(): string
    {
        return 'hello underscore';
    }

    public function arr(string ...$args): string
    {
        return 'hello ' . implode(',', $args);
    }

    public function arrplus(string $test, string ...$args): string
    {
        return 'hello ' . $test . ',' . implode(',', $args);
    }
}
