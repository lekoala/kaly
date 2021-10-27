<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\App;
use Exception;
use Kaly\Auth;
use Kaly\Exceptions\RedirectException;
use Kaly\Exceptions\ValidationException;
use Psr\Http\Message\ServerRequestInterface;

class IndexController
{
    protected App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function index(ServerRequestInterface $request)
    {
        return 'hello';
    }

    public function isinvalid()
    {
        return 'never returns';
    }

    public function foo(ServerRequestInterface $request)
    {
        return 'foo';
    }

    public function arr(ServerRequestInterface $request, array $arr)
    {
        return $arr;
    }

    public function methodGet(ServerRequestInterface $request)
    {
        return 'get';
    }

    public function methodPost(ServerRequestInterface $request)
    {
        return 'post';
    }

    public function middleware(ServerRequestInterface $request)
    {
        $attr = $request->getAttribute("test-attribute");
        return $attr;
    }

    public function middlewareException(ServerRequestInterface $request)
    {
        throw new Exception($request->getAttribute("test-attribute"));
    }

    public function getip(ServerRequestInterface $request)
    {
        return $request->getAttribute("client-ip");
    }

    public function getipstate(ServerRequestInterface $request)
    {
        return $this->app->getRequest()->getAttribute("client-ip");
    }

    public function redirect(ServerRequestInterface $request)
    {
        throw new RedirectException("/test-module");
    }

    public function validation(ServerRequestInterface $request)
    {
        throw new ValidationException("This is invalid");
    }

    public function auth(ServerRequestInterface $request)
    {
        Auth::basicAuth($request, "unit", "test");
    }
}
