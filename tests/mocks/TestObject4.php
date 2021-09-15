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

    public function testMethod($val)
    {
        $this->test[] = $val;
    }

    public function testMethod2(array $val, string $other = 'wrong')
    {
        $this->test2 = $val;
        $this->other = $other;
    }

    public function testMethod3(array $val)
    {
        $this->test3 = $val;
    }
}
