<?php

namespace RoutableModule\Controller;

use Kaly\Core\AbstractController;

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
}
