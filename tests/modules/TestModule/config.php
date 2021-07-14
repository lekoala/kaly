<?php

use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestInterface;

$value_is_not_leaked = "test";

return [
    TestInterface::class => function () {
        return new TestObject();
    }
];
