<?php

namespace SimpleModule\Controller;

use Kaly\Core\AbstractController;

class IndexController extends AbstractController
{
    public function index($param = 'world')
    {
        return 'hello ' . $param;
    }

    public function demo()
    {
        return 'hello demo';
    }

    public function arrplus(string $test, ...$args): string
    {
        return "hello " . $test . ',' . implode(",", $args);
    }
}
