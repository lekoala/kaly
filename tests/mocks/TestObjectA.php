<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

class TestObjectA
{
    protected TestObjectB $obj;

    public function __construct(TestObjectB $obj)
    {
        $this->obj = $obj;
    }
}
