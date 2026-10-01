<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use PDO;

class TestObject4
{
    public PDO $pdo;
    public string $bar;
    public string $baz;
    /** @var array<mixed> */
    public array $arr;
    /** @var list<mixed> */
    public array $test = [];
    /** @var array<mixed> */
    public array $test2 = [];
    /** @var array<mixed> */
    public array $test3 = [];
    public string $other;
    /** @var list<string> */
    public array $queue = [];

    /** @param array<mixed> $arr */
    public function __construct(PDO $pdo, string $bar, string $baz = 'baz-wrong', array $arr = [])
    {
        $this->pdo = $pdo;
        $this->bar = $bar;
        $this->baz = $baz;
        $this->arr = $arr;
    }

    /** @param mixed $val */
    public function testMethod($val): void
    {
        $this->test[] = $val;
    }

    /** @param array<mixed> $val */
    public function testMethod2(array $val, string $other = 'wrong'): void
    {
        $this->test2 = $val;
        $this->other = $other;
    }

    /** @param array<mixed> $val */
    public function testMethod3(array $val): void
    {
        $this->test3 = $val;
    }

    public function testQueue(string $val): void
    {
        $this->queue[] = $val;
    }
}
