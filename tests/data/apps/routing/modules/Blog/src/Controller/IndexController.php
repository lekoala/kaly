<?php

declare(strict_types=1);

namespace Blog\Controller;

final class IndexController
{
    public function index(): string
    {
        return 'blog';
    }
}
