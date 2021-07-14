<?php

namespace TestModule\Controller;

use Psr\Http\Message\ServerRequestInterface;

class DemoController
{
    protected ServerRequestInterface $request;

    public function __construct(ServerRequestInterface $request)
    {
        $this->request = $request;
    }

    public function index($param = "")
    {
        if ($param) {
            return "hello $param";
        }
        return "hello demo";
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
