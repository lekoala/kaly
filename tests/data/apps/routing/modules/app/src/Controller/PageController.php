<?php

declare(strict_types=1);

namespace App\Controller;

use App\Page;

final class PageController
{
    public function __construct(
        private Page $page,
    ) {}

    public function index(): string
    {
        return 'page:' . $this->page->title;
    }

    public function print(): string
    {
        return 'print:' . $this->page->title;
    }
}
