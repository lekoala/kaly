<?php

use Psr\Log\LoggerInterface;

// A basic class taking a parameter
class SomeClass
{
    protected float $v;
    public function __construct(
        public LoggerInterface $logger
    ) {
        // provide an unique value
        $this->v = microtime(true);
    }
}

// A circular scenario
class CircularA
{
    public function __construct(
        public CircularB $inst
    ) {}
}

class CircularB
{
    public function __construct(
        public CircularA $inst
    ) {}
}

class ExtendedPDO extends PDO {}
class BackupPDO extends PDO {}

class WrongClass
{
    public function __construct(
        public string $dsn
    ) {}
}

class UnionClass
{
    protected float $v;
    public function __construct(
        public LoggerInterface|string $logger
    ) {
        // provide an unique value
        $this->v = microtime(true);
    }
}

// https://php.watch/versions/8.1/intersection-types
class IntersectionClass
{
    public function __construct(
        public \Iterator&\Countable $v
    ) {
        //
    }
}

class IterableAndCountable implements \Iterator, \Countable
{
    public function count(): int
    {
        return 0;
    }

    public function rewind(): void
    {
        //
    }

    public function current(): mixed
    {
        return null;
    }

    public function key(): mixed
    {
        return 0;
    }

    public function next(): void
    {
        //
    }

    public function valid(): bool
    {
        return true;
    }
}

// A class that takes two pdo instances
// Named service can be found based on variable name or using the reference definition
class BackupService
{
    public function __construct(
        public PDO $db,
        public PDO $backupDb
    ) {}
}

class App {}
