<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

interface TransactionInterface
{
    public function commit(): void;

    public function rollback(): void;
}
