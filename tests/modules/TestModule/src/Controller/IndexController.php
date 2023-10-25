<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\App;
use Exception;
use JsonSerializable;
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
        $obj = new class($arr) implements JsonSerializable
        {
            protected array $data;
            public function __construct(array $data)
            {
                $this->data = $data;
            }
            public function jsonSerialize(): mixed
            {
                return $this->data;
            }
        };
        return $obj;
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
        /** @var Auth $auth  */
        $auth = $this->app->getDi()->get(Auth::class);
        $auth->basicAuth("unit", "test");
    }
}
