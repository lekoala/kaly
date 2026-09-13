<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

class TestObjectB
{
    protected TestObjectA $obj;

    public function __construct(TestObjectA $obj)
    {
        $this->obj = $obj;
    }
}
