<?php

declare(strict_types=1);

namespace TestVendor\MappedModule\Controller;

use Psr\Http\Message\ServerRequestInterface;

class IndexController
{
    public function index(ServerRequestInterface $request)
    {
        return 'mapped';
    }
}
