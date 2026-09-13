<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

class TestObjectA
{
    protected TestObjectB $obj;

    public function __construct(TestOBjectB $obj)
    {
        $this->obj = $obj;
    }
}
