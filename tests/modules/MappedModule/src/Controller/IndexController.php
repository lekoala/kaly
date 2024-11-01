<?php

declare(strict_types=1);

namespace TestVendor\MappedModule\Controller;

class IndexController
{
    public function index(): string
    {
        return 'mapped';
    }
}
