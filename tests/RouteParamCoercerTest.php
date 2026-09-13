<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Router\RouteNotFoundException;
use Kaly\Router\RouteParamCoercer;
use PHPUnit\Framework\TestCase;

class RouteParamCoercerTest extends TestCase
{
    public function testCoerceInt(): void
    {
        $this->assertSame(123, RouteParamCoercer::coerce('int', '123'));
        $this->assertSame(123, RouteParamCoercer::int('123'));
    }

    public function testCoerceInvalidIntIsNotFound(): void
    {
        $this->expectException(RouteNotFoundException::class);
        RouteParamCoercer::coerce('int', '12a');
    }

    public function testCoerceFloat(): void
    {
        $this->assertSame(1.5, RouteParamCoercer::coerce('float', '1.5'));
    }

    public function testCoerceInvalidFloatIsNotFound(): void
    {
        $this->expectException(RouteNotFoundException::class);
        RouteParamCoercer::coerce('float', 'a lot');
    }

    public function testCoerceBool(): void
    {
        $this->assertTrue(RouteParamCoercer::coerce('bool', '1'));
        $this->assertFalse(RouteParamCoercer::coerce('bool', 'false'));
    }

    public function testCoerceInvalidBoolIsNotFound(): void
    {
        $this->expectException(RouteNotFoundException::class);
        RouteParamCoercer::coerce('bool', 'maybe');
    }

    public function testCoerceList(): void
    {
        $this->assertSame(['a', 'b'], RouteParamCoercer::coerce('array', 'a,b'));
    }

    public function testCoerceStringPassesThrough(): void
    {
        $this->assertSame('hello', RouteParamCoercer::coerce('string', 'hello'));
        $this->assertSame('hello', RouteParamCoercer::coerce('unknown', 'hello'));
    }
}
