<?php

namespace TestModule\Controller;

use Kaly\State;
use Psr\Http\Message\ServerRequestInterface;

class DemoController
{
    protected ServerRequestInterface $request;
    protected State $state;

    public function __construct(ServerRequestInterface $request, State $state)
    {
        $this->request = $request;
        $this->state = $state;
    }

    public function index($param = "")
    {
        if ($param) {
            return "hello $param";
        }
        return "hello demo";
    }

    public function methodGet()
    {
        return 'get';
    }

    public function methodPost()
    {
        return 'post';
    }

    public function func()
    {
        return "hello func";
    }

    public function arr(...$args)
    {
        return "hello " . implode(",", $args);
    }
}
