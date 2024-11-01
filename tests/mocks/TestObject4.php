<?php

namespace Kaly\Tests\Mocks;

use PDO;

class TestObject4
{
    public PDO $pdo;
    public string $bar;
    public string $baz;
    public array $arr;
    public array $test = [];
    public array $test2 = [];
    public array $test3 = [];
    public string $other;
    public array $queue = [];

    public function __construct(
        PDO $pdo,
        string $bar,
        string $baz = 'baz-wrong',
        array $arr = []
    ) {
        $this->pdo = $pdo;
        $this->bar = $bar;
        $this->baz = $baz;
        $this->arr = $arr;
    }

    public function testMethod($val): void
    {
        $this->test[] = $val;
    }

    public function testMethod2(array $val, string $other = 'wrong'): void
    {
        $this->test2 = $val;
        $this->other = $other;
    }

    public function testMethod3(array $val): void
    {
        $this->test3 = $val;
    }

    public function testQueue(string $val): void
    {
        $this->queue[] = $val;
    }
}
