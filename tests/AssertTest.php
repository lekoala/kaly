<?php

declare(strict_types=1);

namespace Kaly\Tests;

use ArrayObject;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Kaly\Ex;
use Kaly\Util\Assert;
use PHPUnit\Framework\TestCase;
use stdClass;

class AssertTest extends TestCase
{
    public function testNotNull(): void
    {
        $this->assertSame('0', Assert::notNull('0'));
        $this->assertSame(0, Assert::notNull(0));
        $this->assertFalse(Assert::notNull(false));
        $this->assertSame([], Assert::notNull([]));
        // A nullable input comes back without null (see requireObject below)
        $object = new stdClass();
        $this->assertSame($object, $this->requireObject($object));
    }

    public function testNotNullRefusesNull(): void
    {
        $this->expectException(\Kaly\Ex::class);
        $this->expectExceptionMessage('Expected non-null value, got null');
        Assert::notNull(null);
    }

    public function testString(): void
    {
        $this->assertSame('', Assert::string(''));
        $this->assertSame('42', Assert::string('42'));
        try {
            Assert::string(42);
            $this->fail('string should have failed');
        } catch (\Kaly\Ex $exception) {
            $this->assertSame('Expected string, got int', $exception->getMessage());
        }
    }

    public function testStringNotEmpty(): void
    {
        $this->assertSame('0', Assert::stringNotEmpty('0'));
        $this->assertSame(' x ', Assert::stringNotEmpty(' x '));
        foreach (['', null, 0, false, []] as $value) {
            try {
                Assert::stringNotEmpty($value);
                $this->fail('stringNotEmpty should have failed');
            } catch (\Kaly\Ex $exception) {
                $this->assertSame('Expected non-empty string, got ' . get_debug_type($value), $exception->getMessage());
            }
        }
    }

    public function testInteger(): void
    {
        $this->assertSame(0, Assert::integer(0));
        $this->assertSame(-5, Assert::integer(-5));
        foreach (['42', 4.0, true, null] as $value) {
            try {
                Assert::integer($value);
                $this->fail('integer should have failed');
            } catch (\Kaly\Ex $exception) {
                $this->assertSame('Expected integer, got ' . get_debug_type($value), $exception->getMessage());
            }
        }
    }

    public function testPositiveInteger(): void
    {
        $this->assertSame(1, Assert::positiveInteger(1));
        foreach ([0, -1, '1', 1.0, null] as $value) {
            try {
                Assert::positiveInteger($value);
                $this->fail('positiveInteger should have failed');
            } catch (\Kaly\Ex $exception) {
                $this->assertSame('Expected positive integer, got ' . get_debug_type($value), $exception->getMessage());
            }
        }
    }

    public function testBoolean(): void
    {
        $this->assertTrue(Assert::boolean(true));
        $this->assertFalse(Assert::boolean(false));
        foreach ([0, 1, 'true', null] as $value) {
            try {
                Assert::boolean($value);
                $this->fail('boolean should have failed');
            } catch (\Kaly\Ex $exception) {
                $this->assertSame('Expected boolean, got ' . get_debug_type($value), $exception->getMessage());
            }
        }
    }

    public function testArray(): void
    {
        $this->assertSame([], Assert::array([]));
        $this->assertSame(['a' => 1], Assert::array(['a' => 1]));
        $this->expectException(\Kaly\Ex::class);
        Assert::array('foo');
    }

    public function testInstanceOf(): void
    {
        $object = new stdClass();
        $this->assertSame($object, Assert::instanceOf($object, stdClass::class));
        $mutable = new DateTime('2026-01-01');
        $this->assertSame($mutable, Assert::instanceOf($mutable, DateTimeInterface::class));
        $child = new class extends stdClass {};
        $this->assertSame($child, Assert::instanceOf($child, stdClass::class));
        try {
            Assert::instanceOf(new ArrayObject(), stdClass::class);
            $this->fail('instanceOf should have failed');
        } catch (\Kaly\Ex $exception) {
            $this->assertSame('Expected instance of stdClass, got ArrayObject', $exception->getMessage());
        }
    }

    public function testIsList(): void
    {
        $this->assertSame([], Assert::isList([]));
        $this->assertSame([1, 'two'], Assert::isList([1, 'two']));
        foreach ([['a' => 1], [1 => 'x'], 'foo', null] as $value) {
            try {
                Assert::isList($value);
                $this->fail('isList should have failed');
            } catch (\Kaly\Ex $exception) {
                $this->assertSame('Expected list, got ' . get_debug_type($value), $exception->getMessage());
            }
        }
    }

    public function testIsMap(): void
    {
        // An empty array is valid both as a list and as a map
        $this->assertSame([], Assert::isMap([]));
        $this->assertSame(['foo' => 1], Assert::isMap(['foo' => 1]));
        foreach ([[1, 2], ['foo' => 1, 0 => 2], ['0' => 'x'], 'foo', null] as $value) {
            try {
                Assert::isMap($value);
                $this->fail('isMap should have failed');
            } catch (\Kaly\Ex $exception) {
                $this->assertSame('Expected map, got ' . get_debug_type($value), $exception->getMessage());
            }
        }
    }

    public function testAllInstanceOf(): void
    {
        $first = new stdClass();
        $child = new class extends stdClass {};
        $this->assertSame([], Assert::allInstanceOf([], stdClass::class));
        $this->assertSame([$first, $child], Assert::allInstanceOf([$first, $child], stdClass::class));
        $mutable = new DateTime('2026-01-01');
        $immutable = new DateTimeImmutable('2026-01-02');
        $this->assertSame([$mutable, $immutable], Assert::allInstanceOf([$mutable, $immutable], DateTimeInterface::class));
        // Never filters: a single invalid element fails the whole assertion
        try {
            Assert::allInstanceOf([$first, 'invalid'], stdClass::class);
            $this->fail('allInstanceOf should have failed');
        } catch (\Kaly\Ex $exception) {
            $this->assertSame('Expected list of stdClass, got element of type string', $exception->getMessage());
        }
    }

    public function testAllInstanceOfRejectsNonLists(): void
    {
        foreach ([null, 'value', new stdClass(), ['item' => new stdClass()], [1 => new stdClass()]] as $value) {
            try {
                Assert::allInstanceOf($value, stdClass::class);
                $this->fail('allInstanceOf should have failed');
            } catch (\Kaly\Ex $exception) {
                $this->assertSame('Expected list of stdClass, got ' . get_debug_type($value), $exception->getMessage());
            }
        }
    }

    public function testKeyExists(): void
    {
        // A null value still counts as existing
        $this->assertSame(['a' => null], Assert::keyExists(['a' => null], 'a'));
        $this->assertSame([0 => 'x'], Assert::keyExists([0 => 'x'], 0));
        try {
            Assert::keyExists(['a' => 1], 'b');
            $this->fail('keyExists should have failed');
        } catch (\Kaly\Ex $exception) {
            $this->assertSame("Expected array with key 'b'", $exception->getMessage());
        }
        $this->expectException(\Kaly\Ex::class);
        Assert::keyExists('foo', 'a');
    }

    public function testSame(): void
    {
        $this->assertSame('0', Assert::same('0', '0'));
        $this->assertSame(0, Assert::same(0, 0));
        $object = new stdClass();
        $this->assertSame($object, Assert::same($object, $object));
        foreach ([[0, '0'], [false, null], [[1], ['1']]] as [$value, $expected]) {
            try {
                Assert::same($value, $expected);
                $this->fail('same should have failed');
            } catch (\Kaly\Ex $exception) {
                $this->assertSame(
                    'Expected identical ' . get_debug_type($expected) . ', got ' . get_debug_type($value),
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testOneOf(): void
    {
        $this->assertSame('a', Assert::oneOf('a', ['a', 'b']));
        $this->assertSame(0, Assert::oneOf(0, [0, 1]));
        $this->assertFalse(Assert::oneOf(false, [false, true]));
        try {
            Assert::oneOf('a', ['A', 'b']);
            $this->fail('oneOf should have failed');
        } catch (\Kaly\Ex $exception) {
            $this->assertSame('Expected one of the allowed values, got string', $exception->getMessage());
        }
        $this->expectException(\Kaly\Ex::class);
        Assert::oneOf(1, ['1', '2']);
    }

    public function testGreaterThan(): void
    {
        $this->assertSame(6, Assert::greaterThan(6, 5));
        $this->assertSame(5.5, Assert::greaterThan(5.5, 5));
        try {
            Assert::greaterThan(5, 5);
            $this->fail('greaterThan should have failed');
        } catch (\Kaly\Ex $exception) {
            $this->assertSame('Expected number greater than 5', $exception->getMessage());
        }
        $this->expectException(\Kaly\Ex::class);
        Assert::greaterThan('6', 5);
    }

    public function testGreaterThanRefusesNan(): void
    {
        try {
            Assert::greaterThan(NAN, 5);
            $this->fail('greaterThan should have failed');
        } catch (\Kaly\Ex $exception) {
            $this->assertSame('Expected number greater than a valid bound, got NAN', $exception->getMessage());
        }
    }

    public function testRange(): void
    {
        $this->assertSame(5, Assert::range(5, 1, 10));
        $this->assertSame(1, Assert::range(1, 1, 10));
        $this->assertSame(10, Assert::range(10, 1, 10));
        $this->assertSame(1.5, Assert::range(1.5, 1, 2));
        try {
            Assert::range(0, 1, 10);
            $this->fail('range should have failed');
        } catch (\Kaly\Ex $exception) {
            $this->assertSame('Expected number between 1 and 10', $exception->getMessage());
        }
        $this->expectException(\Kaly\Ex::class);
        Assert::range('5', 1, 10);
    }

    public function testRangeRefusesNan(): void
    {
        // Plain comparisons would let NAN through, as every comparison is false
        foreach ([[NAN, 1.0, 10.0], [5.0, NAN, 10.0], [5.0, 1.0, NAN]] as [$value, $min, $max]) {
            try {
                Assert::range($value, $min, $max);
                $this->fail('range should have failed');
            } catch (\Kaly\Ex $exception) {
                $this->assertSame('Expected number within valid bounds, got NAN', $exception->getMessage());
            }
        }
    }

    public function testRangeGuardsBounds(): void
    {
        $this->expectException(\Kaly\Ex::class);
        $this->expectExceptionMessage('The minimum must not be greater than the maximum');
        Assert::range(5, 10, 1);
    }

    public function testStartsWith(): void
    {
        $this->assertSame('foobar', Assert::startsWith('foobar', 'foo'));
        try {
            Assert::startsWith('foobar', 'bar');
            $this->fail('startsWith should have failed');
        } catch (\Kaly\Ex $exception) {
            $this->assertSame("Expected string starting with 'bar'", $exception->getMessage());
        }
        $this->expectException(\Kaly\Ex::class);
        Assert::startsWith(42, '4');
    }

    public function testContains(): void
    {
        $this->assertSame('foobar', Assert::contains('foobar', 'oba'));
        try {
            Assert::contains('foobar', 'baz');
            $this->fail('contains should have failed');
        } catch (\Kaly\Ex $exception) {
            $this->assertSame("Expected string containing 'baz'", $exception->getMessage());
        }
        $this->expectException(\Kaly\Ex::class);
        Assert::contains(null, 'x');
    }

    public function testMatches(): void
    {
        $this->assertSame('abc123', Assert::matches('abc123', '/^[a-z0-9]+$/'));
        try {
            Assert::matches('ABC', '/^[a-z]+$/');
            $this->fail('matches should have failed');
        } catch (\Kaly\Ex $exception) {
            $this->assertSame('Expected string matching the pattern', $exception->getMessage());
        }
        $this->expectException(\Kaly\Ex::class);
        Assert::matches(42, '/^[0-9]+$/');
    }

    public function testMatchesGuardsInvalidPattern(): void
    {
        $this->expectException(\Kaly\Ex::class);
        $this->expectExceptionMessage('Invalid regular expression');
        Assert::matches('foo', '([');
    }

    public function testLengthBetween(): void
    {
        $this->assertSame('foo', Assert::lengthBetween('foo', 1, 5));
        $this->assertSame('', Assert::lengthBetween('', 0, 0));
        $this->assertSame('éclair', Assert::lengthBetween('éclair', 6, 6));
        try {
            Assert::lengthBetween('toolong', 1, 3);
            $this->fail('lengthBetween should have failed');
        } catch (\Kaly\Ex $exception) {
            $this->assertSame('Expected string length between 1 and 3', $exception->getMessage());
        }
        $this->expectException(\Kaly\Ex::class);
        Assert::lengthBetween(null, 0, 5);
    }

    public function testLengthBetweenGuardsBounds(): void
    {
        try {
            Assert::lengthBetween('foo', 5, 1);
            $this->fail('lengthBetween should have failed');
        } catch (\Kaly\Ex $exception) {
            $this->assertSame('The minimum must not be greater than the maximum', $exception->getMessage());
        }
        $this->expectException(\Kaly\Ex::class);
        $this->expectExceptionMessage('Bounds must not be negative');
        Assert::lengthBetween('foo', -1, 5);
    }

    public function testCustomMessageReplacesDefault(): void
    {
        try {
            Assert::string(42, 'Custom failure');
            $this->fail('string should have failed');
        } catch (\Kaly\Ex $exception) {
            $this->assertSame('Custom failure', $exception->getMessage());
        }
        // Even an empty string is an explicit custom message
        try {
            Assert::string(42, '');
            $this->fail('string should have failed');
        } catch (\Kaly\Ex $exception) {
            $this->assertSame('', $exception->getMessage());
        }
    }

    public function testDefaultMessagesNeverExposeValues(): void
    {
        try {
            Assert::stringNotEmpty(42);
            $this->fail('stringNotEmpty should have failed');
        } catch (\Kaly\Ex $exception) {
            $this->assertSame('Expected non-empty string, got int', $exception->getMessage());
        }
    }

    private function requireObject(?stdClass $maybe): stdClass
    {
        return Assert::notNull($maybe);
    }
}
