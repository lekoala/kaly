<?php

declare(strict_types=1);

namespace LangModule\Controller;

use Kaly\Core\AbstractController;

class IndexController extends AbstractController
{
    public function getlang(): string
    {
        return (string) $this->ctx()->locale;
    }
}
