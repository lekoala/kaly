<?php

declare(strict_types=1);

namespace RoutableModule\Controller;

use Kaly\Core\AbstractController;

class HelpController extends AbstractController
{
    public function index(): string
    {
        return 'help';
    }
}
