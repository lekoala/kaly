<?php

namespace Kaly\Tests\Mocks;

class TestObject2
{
    public static int $counter = 0;
    public string $v;
    public function __construct(string $v)
    {
        self::$counter++;
        $this->v = $v;
    }
}
