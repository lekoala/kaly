<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Core\AbstractController;

class MixedController extends AbstractController
{
    public function index(string ...$segments): string
    {
        return 'mixed-index:' . implode(',', $segments);
    }

    public function buy(): string
    {
        return 'mixed-buy';
    }

    public function buyPost(): string
    {
        return 'mixed-bought';
    }
}
