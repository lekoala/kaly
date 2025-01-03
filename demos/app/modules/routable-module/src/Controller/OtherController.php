<?php

namespace RoutableModule\Controller;

use Kaly\Core\AbstractController;

class OtherController extends AbstractController
{
    public function index($param = 'world')
    {
        return 'other ' . $param;
    }

    public function demo()
    {
        return 'other demo';
    }
}
