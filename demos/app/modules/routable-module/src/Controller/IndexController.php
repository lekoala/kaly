<?php

namespace RoutableModule\Controller;

use Kaly\Core\AbstractController;
use Kaly\Router\RouteAttribute;

class IndexController extends AbstractController
{
    public function index($param = 'world')
    {
        return 'hello ' . $param;
    }

    public function demo()
    {
        return 'hello demo';
    }

    #[RouteAttribute('/hello-explicit', methods: ['GET'], name: 'demo.hello-explicit')]
    public function explicit(): string
    {
        return 'hello explicit';
    }
}
