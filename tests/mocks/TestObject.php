<?php

namespace Kaly\Tests\Mocks;

class TestObject implements TestInterface
{
    public string $val;

    public function getVal(): string
    {
        return $this->val;
    }

    public function setVal(string $val): void
    {
        $this->val = $val;
    }
}
