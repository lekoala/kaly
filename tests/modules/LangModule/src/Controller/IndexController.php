<?php

declare(strict_types=1);

namespace LangModule\Controller;

use Kaly\State;
use Psr\Http\Message\ServerRequestInterface;

class IndexController
{
    protected State $state;

    public function __construct(State $state)
    {
        $this->state = $state;
    }

    public function getlang(ServerRequestInterface $request)
    {
        return $this->state->getLocale();
    }
}
