<?php

namespace Kaly\Tests\Mocks;

use PDO;

class TestObject4
{
    public PDO $pdo;
    public string $bar;
    public string $baz;
    public array $arr;

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
}
