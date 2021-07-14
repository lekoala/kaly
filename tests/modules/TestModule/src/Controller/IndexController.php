<?php

namespace TestModule\Controller;

use Kaly\Util;
use Kaly\Exceptions\RedirectException;
use Kaly\Exceptions\ValidationException;
use Psr\Http\Message\ServerRequestInterface;

class IndexController
{
    protected ServerRequestInterface $request;

    public function __construct(ServerRequestInterface $request)
    {
        $this->request = $request;
    }

    public function index()
    {
        return 'hello';
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
        Util::basicAuth($this->request, "unit", "test");
    }
}
