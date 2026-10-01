<?php

declare(strict_types=1);

namespace RoutableModule\Controller;

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

    public function explicit(): string
    {
        return 'hello explicit';
    }
}
