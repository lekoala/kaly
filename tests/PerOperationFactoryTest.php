<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Fiber;
use PHPUnit\Framework\TestCase;

/**
 * Lot E validation: stateful resources live per operation, not per request.
 *
 * A shared factory creates a fresh unit of work for every `with()` call and
 * discards it afterwards. Sequential and interleaved (Fiber) operations never
 * share state, and a failed operation does not contaminate the next one — with
 * no request-scope primitive from the framework.
 */
class PerOperationFactoryTest extends TestCase
{
    public function testOperationsNeverShareState(): void
    {
        $factory = new FakeUnitOfWorkFactory();

        $first = $factory->with(static fn(FakeUnitOfWork $uow): string => $uow->write('a'));
        $second = $factory->with(static fn(FakeUnitOfWork $uow): string => $uow->write('b'));

        $this->assertSame('a', $first);
        $this->assertSame('b', $second);
        $this->assertSame(2, $factory->created);
    }

    public function testAFailedOperationIsDiscarded(): void
    {
        $factory = new FakeUnitOfWorkFactory();

        $failures = [];
        try {
            $factory->with(static function (FakeUnitOfWork $uow): void {
                $uow->write('dirty');
                throw new \RuntimeException('flush failed');
            });
        } catch (\RuntimeException $e) {
            $failures[] = $e->getMessage();
        }
        $this->assertSame(['flush failed'], $failures);

        $clean = $factory->with(static fn(FakeUnitOfWork $uow): string => $uow->write('clean'));

        $this->assertSame('clean', $clean);
        $this->assertSame(2, $factory->created);
    }

    public function testInterleavedOperationsStayIsolated(): void
    {
        $factory = new FakeUnitOfWorkFactory();
        $seen = [];

        $fiberA = new Fiber(static function () use ($factory, &$seen): void {
            $factory->with(static function (FakeUnitOfWork $uow) use (&$seen): void {
                $uow->write('A1');
                Fiber::suspend();
                $seen['A'] = $uow->read();
            });
        });
        $fiberB = new Fiber(static function () use ($factory, &$seen): void {
            $factory->with(static function (FakeUnitOfWork $uow) use (&$seen): void {
                $uow->write('B1');
                $seen['B'] = $uow->read();
            });
        });

        $fiberA->start();
        $fiberB->start();
        $fiberA->resume();

        $this->assertSame(['B1'], $seen['B']);
        $this->assertSame(['A1'], $seen['A']);
    }
}

final class FakeUnitOfWork
{
    /** @var list<string> */
    private array $writes = [];

    public function write(string $value): string
    {
        $this->writes[] = $value;
        return $value;
    }

    /**
     * @return list<string>
     */
    public function read(): array
    {
        return $this->writes;
    }
}

final class FakeUnitOfWorkFactory
{
    public int $created = 0;

    /**
     * @template T
     * @param callable(FakeUnitOfWork): T $operation
     * @return T
     */
    public function with(callable $operation): mixed
    {
        $this->created++;
        return $operation(new FakeUnitOfWork());
    }
}
