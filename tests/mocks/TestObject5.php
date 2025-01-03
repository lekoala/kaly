<?php

namespace Kaly\Tests\Mocks;

class TestObject5 implements TestAltInterface
{
    public string $v;
    public string $v2;
    public ?string $v3;
    public array $arr;
    public function __construct(string $v, string $v2, array $arr, ?string $v3 = null)
    {
        $this->v = $v;
        $this->v2 = $v2;
        $this->v3 = $v3;
        $this->arr = $arr;
    }
}
