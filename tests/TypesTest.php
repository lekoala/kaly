<?php

declare(strict_types=1);

namespace Kaly\Tests;

use ArrayObject;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Kaly\Util\Types;
use PHPUnit\Framework\TestCase;
use stdClass;

class TypesTest extends TestCase
{
    public function testStringOrNull(): void
    {
        $this->assertSame('foo', Types::stringOrNull('foo'));
        // A numeric string is still a string: no conversion happens either way.
        $this->assertSame('42', Types::stringOrNull('42'));
        $this->assertNull(Types::stringOrNull(42));
        $this->assertNull(Types::stringOrNull(null));
        $this->assertNull(Types::stringOrNull(['foo']));
    }

    public function testIntOrNull(): void
    {
        $this->assertSame(42, Types::intOrNull(42));
        $this->assertNull(Types::intOrNull('42'));
        $this->assertNull(Types::intOrNull(4.2));
        $this->assertNull(Types::intOrNull(null));
    }

    public function testBoolOrNull(): void
    {
        $this->assertTrue(Types::boolOrNull(true) ?? false);
        $this->assertFalse(Types::boolOrNull(false) ?? true);
        $this->assertNull(Types::boolOrNull(1));
        $this->assertNull(Types::boolOrNull('true'));
        $this->assertNull(Types::boolOrNull(null));
    }

    public function testListOrEmpty(): void
    {
        $this->assertSame([1, 'two'], Types::listOrEmpty([1, 'two']));
        $this->assertSame([], Types::listOrEmpty(['a' => 1]));
        $this->assertSame([], Types::listOrEmpty('foo'));
        $this->assertSame([], Types::listOrEmpty(null));
    }

    public function testMapOrEmpty(): void
    {
        $this->assertSame(['a' => 1], Types::mapOrEmpty(['a' => 1]));
        $this->assertSame([], Types::mapOrEmpty([1, 2]));
        $this->assertSame([], Types::mapOrEmpty(['0' => 'x']));
        $this->assertSame([], Types::mapOrEmpty('foo'));
        $this->assertSame([], Types::mapOrEmpty(null));
    }

    public function testInstancesOfFiltersAndReindexesWithoutChangingObjects(): void
    {
        $first = new stdClass();
        $subclass = new class extends stdClass {};
        $this->assertSame(
            [$first, $subclass, $first],
            Types::instancesOf([null, $first, 'stdClass', new ArrayObject(), $subclass, 42, $first], stdClass::class),
        );
    }

    public function testInstancesOfAcceptsInterfaces(): void
    {
        $mutable = new DateTime('2026-01-01');
        $immutable = new DateTimeImmutable('2026-01-02');
        $this->assertSame([$mutable, $immutable], Types::instancesOf([$mutable, false, $immutable], DateTimeInterface::class));
    }

    public function testInstancesOfReturnsEmptyForNonListsOrNoMatches(): void
    {
        $object = new stdClass();
        foreach ([null, 'value', $object, new ArrayObject([$object]), ['item' => $object], [1 => $object], [], [false, 42]] as $value) {
            $this->assertSame([], Types::instancesOf($value, stdClass::class));
        }
    }
}
