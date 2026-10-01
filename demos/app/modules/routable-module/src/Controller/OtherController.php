<?php

declare(strict_types=1);

namespace RoutableModule\Controller;

use Kaly\Core\AbstractController;

class OtherController extends AbstractController
{
    public function index(string $param = 'world'): string
    {
        return 'other ' . $param;
    }

    public function demo(): string
    {
        return 'other demo';
    }
}
