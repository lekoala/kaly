<?php

namespace LangModule\Controller;

use Kaly\State;

class IndexController
{
    protected State $state;

    public function __construct(State $state)
    {
        $this->state = $state;
    }

    public function getlang()
    {
        return $this->state->getLocale();
    }
}
