<?php

namespace App\Controller;

use Kaly\Core\AbstractController;

class IndexController extends AbstractController
{
    public function index()
    {
        return [
            'content' => 'hello from index',
            'unsafe' => '<script>alert("test")</script>',
            'list' => ['a', 'list', 'of', 'items', 'un<safe>', 150, null, true, ['test', true, false, 10]], // including silly stuff to escape
        ];
    }

    public function demo()
    {
        return 'hello';
    }
}
