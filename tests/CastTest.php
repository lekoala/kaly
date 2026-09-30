<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Util\Cast;
use PHPUnit\Framework\TestCase;

class CastTest extends TestCase
{
    public function testIntOrNull(): void
    {
        $this->assertSame(42, Cast::intOrNull(42));
        $this->assertSame(42, Cast::intOrNull('42'));
        $this->assertSame(0, Cast::intOrNull('0'));
        $this->assertNull(Cast::intOrNull('4.2'));
        $this->assertNull(Cast::intOrNull('abc'));
        $this->assertNull(Cast::intOrNull(4.2));
        $this->assertNull(Cast::intOrNull(null));
    }

    public function testFloatOrNull(): void
    {
        $this->assertSame(1.5, Cast::floatOrNull(1.5));
        $this->assertSame(1.0, Cast::floatOrNull(1));
        $this->assertSame(1.5, Cast::floatOrNull('1.5'));
        $this->assertNull(Cast::floatOrNull('abc'));
        $this->assertNull(Cast::floatOrNull(null));
    }

    public function testBoolOrNull(): void
    {
        $this->assertTrue(Cast::boolOrNull(true));
        $this->assertTrue(Cast::boolOrNull('1'));
        $this->assertTrue(Cast::boolOrNull('true'));
        $this->assertFalse(Cast::boolOrNull(false));
        $this->assertFalse(Cast::boolOrNull('0'));
        $this->assertNull(Cast::boolOrNull('maybe'));
        $this->assertNull(Cast::boolOrNull([]));
    }
}
