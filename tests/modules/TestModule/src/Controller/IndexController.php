<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Exception;
use JsonSerializable;
use Kaly\Core\AbstractController;
use Kaly\Core\App;
use Kaly\Http\RedirectException;
use Kaly\Http\ValidationException;
use Kaly\Security\Auth;

class IndexController extends AbstractController
{
    protected App $app;

    public function index(): string
    {
        return 'hello';
    }

    protected function isinvalid(): string
    {
        return 'never returns because protected';
    }

    public function foo(): string
    {
        return 'foo';
    }

    public function arr(array $arr): object
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

    public function methodGet(): string
    {
        return 'get';
    }

    public function methodPost(): string
    {
        return 'post';
    }

    public function middleware(): string
    {
        $attr = $this->request->getAttribute("test-attribute");
        return (string)$attr;
    }

    public function middlewareException(): never
    {
        throw new Exception($this->request->getAttribute("test-attribute"));
    }

    public function getip()
    {
        return $this->request->getAttribute("client-ip");
    }

    public function getipstate()
    {
        return $this->request->getAttribute("client-ip");
    }

    public function redirect(): never
    {
        throw new RedirectException("/test-module");
    }

    public function validation(): never
    {
        throw new ValidationException("This is invalid");
    }

    public function auth(): void
    {
        $auth = $this->app->get(Auth::class);
        $auth->basicAuth($this->request, "unit", "test");
    }
}
