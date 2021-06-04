<?php

namespace Kaly\Tests\Mocks;

class TestObject3
{
    public TestObject $obj1;
    public TestObject2 $obj2;
    public string $optional;
    public function __construct(TestObject $obj1, TestObject2 $obj2, string $optional = 'default')
    {
        $this->obj1 = $obj1;
        $this->obj2 = $obj2;
        $this->optional = $optional;
    }
}
