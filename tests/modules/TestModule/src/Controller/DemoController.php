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

    public function isRequestDifferent(ServerRequestInterface $request)
    {
        $curr = $this->state->getRequest();
        return $curr !== $this->request ? 'yes' : 'no';
    }

    public function index(ServerRequestInterface $request, $param = "")
    {
        if ($param) {
            return "hello $param";
        }
        return "hello demo";
    }

    public function methodGet(ServerRequestInterface $request)
    {
        return 'get';
    }

    public function methodPost(ServerRequestInterface $request)
    {
        return 'post';
    }

    public function func(ServerRequestInterface $request)
    {
        return "hello func";
    }

    //@codingStandardsIgnoreLine
    public function hello_func(ServerRequestInterface $request)
    {
        return "hello underscore";
    }

    public function arr(ServerRequestInterface $request, ...$args)
    {
        return "hello " . implode(",", $args);
    }
}
