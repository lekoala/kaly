<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\State;
use Psr\Http\Message\ServerRequestInterface;

class DemoController
{
    protected ServerRequestInterface $request;
    protected State $state;

    public function __construct(State $state)
    {
        $this->state = $state;
        $this->request = $state->getRequest();
    }

    public function isRequestDifferent()
    {
        $curr = $this->state->getRequest();
        return $curr !== $this->request ? 'yes' : 'no';
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

    public function hello_func()
    {
        return "hello underscore";
    }

    public function arr(...$args)
    {
        return "hello " . implode(",", $args);
    }
}
