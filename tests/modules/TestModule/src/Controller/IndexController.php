<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Auth;
use Kaly\Exceptions\RedirectException;
use Kaly\Exceptions\ValidationException;
use Kaly\State;

class IndexController
{
    protected State $state;

    public function __construct(State $state)
    {
        $this->state = $state;
    }

    public function index()
    {
        return 'hello';
    }

    public function foo()
    {
        return 'foo';
    }

    public function methodGet()
    {
        return 'get';
    }

    public function methodPost()
    {
        return 'post';
    }

    public function middleware()
    {
        $attr = $this->state->getRequest()->getAttribute("test-attribute");
        return $attr;
    }

    public function getip()
    {
        return $this->state->getRequest()->getAttribute("client-ip");
    }

    public function redirect()
    {
        throw new RedirectException("/test-module");
    }

    public function validation()
    {
        throw new ValidationException("This is invalid");
    }

    public function auth()
    {
        Auth::basicAuth($this->state->getRequest(), "unit", "test");
    }
}
