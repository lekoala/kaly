<?php

namespace Kaly\Tests\Mocks;

class TestObject implements TestInterface
{
    public string $val;
    public int $counter;

    public function getVal(): string
    {
        return $this->val;
    }

    public function setVal(string $val): void
    {
        $this->val = $val;
    }

    public function getCounter(): int
    {
        return $this->counter;
    }

    public function setCounter(int $counter): void
    {
        $this->counter = $counter;
    }
}
