<?php

declare(strict_types=1);

namespace App\Controller;

use Kaly\Core\AbstractController;

final class ContactController extends AbstractController
{
    public function index(): string
    {
        return 'contact:' . $this->ctx()->locale();
    }
}
