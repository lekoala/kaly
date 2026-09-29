<?php

declare(strict_types=1);

namespace App\Controller;

use Kaly\Core\AbstractController;

final class IndexController extends AbstractController
{
    public function index(): string
    {
        return 'home:' . $this->ctx()->locale();
    }
}
