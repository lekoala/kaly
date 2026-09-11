<?php

declare(strict_types=1);

namespace LangModule\Controller;

use Kaly\Core\AbstractController;
use Kaly\Router\RequestDispatcher;

class IndexController extends AbstractController
{
    public function getlang(): mixed
    {
        return $this->request->getAttribute(RequestDispatcher::ATTR_LOCALE_REQUEST);
    }
}
