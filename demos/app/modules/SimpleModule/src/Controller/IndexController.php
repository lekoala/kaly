<?php

declare(strict_types=1);

namespace SimpleModule\Controller;

use Kaly\Core\AbstractController;

class IndexController extends AbstractController
{
    public function index(string $param = 'world'): string
    {
        return 'hello ' . $param;
    }

    public function demo(): string
    {
        return 'hello demo';
    }

    public function arrplus(string $test, string ...$args): string
    {
        return 'hello ' . $test . ',' . implode(',', $args);
    }
}
