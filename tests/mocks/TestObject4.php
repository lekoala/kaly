<?php

namespace Kaly\Tests\Mocks;

use PDO;

class TestObject4
{
    public $pdo;
    public $bar;
    public $baz;

    public function __construct(
        PDO $pdo,
        string $bar,
        string $baz = 'baz-wrong'
    ) {
        $this->pdo = $pdo;
        $this->bar = $bar;
        $this->baz = $baz;
    }
}
