<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Router\ActionSignature;
use Kaly\Router\RouteNotFoundException;
use PHPUnit\Framework\TestCase;

class ActionSignatureTest extends TestCase
{
    public function testCoerceInt(): void
    {
        $this->assertSame(123, ActionSignature::coerce('int', '123'));
    }

    public function testCoerceInvalidIntIsNotFound(): void
    {
        $this->expectException(RouteNotFoundException::class);
        ActionSignature::coerce('int', '12a');
    }

    public function testCoerceFloat(): void
    {
        $this->assertSame(1.5, ActionSignature::coerce('float', '1.5'));
    }

    public function testCoerceInvalidFloatIsNotFound(): void
    {
        $this->expectException(RouteNotFoundException::class);
        ActionSignature::coerce('float', 'a lot');
    }

    public function testCoerceBool(): void
    {
        $this->assertTrue(ActionSignature::coerce('bool', '1'));
        $this->assertFalse(ActionSignature::coerce('bool', 'false'));
    }

    public function testCoerceInvalidBoolIsNotFound(): void
    {
        $this->expectException(RouteNotFoundException::class);
        ActionSignature::coerce('bool', 'maybe');
    }

    public function testCoerceList(): void
    {
        $this->assertSame(['a', 'b'], ActionSignature::coerce('array', 'a,b'));
    }

    public function testCoerceStringPassesThrough(): void
    {
        $this->assertSame('hello', ActionSignature::coerce('string', 'hello'));
        $this->assertSame('hello', ActionSignature::coerce('unknown', 'hello'));
    }
}
