<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http\Method;
use PHPUnit\Framework\TestCase;

class MethodTest extends TestCase
{
    public function testSafeMethods(): void
    {
        $this->assertTrue(Method::isSafe(Method::GET));
        $this->assertTrue(Method::isSafe(Method::HEAD));
        $this->assertTrue(Method::isSafe(Method::OPTIONS));
        $this->assertTrue(Method::isSafe(Method::TRACE));

        $this->assertFalse(Method::isSafe(Method::POST));
        $this->assertFalse(Method::isSafe(Method::PUT));
        $this->assertFalse(Method::isSafe(Method::DELETE));
        $this->assertFalse(Method::isSafe(Method::PATCH));
    }

    public function testIdempotentMethods(): void
    {
        $this->assertTrue(Method::isIdempotent(Method::GET));
        $this->assertTrue(Method::isIdempotent(Method::HEAD));
        $this->assertTrue(Method::isIdempotent(Method::OPTIONS));
        $this->assertTrue(Method::isIdempotent(Method::TRACE));
        $this->assertTrue(Method::isIdempotent(Method::PUT));
        $this->assertTrue(Method::isIdempotent(Method::DELETE));

        $this->assertFalse(Method::isIdempotent(Method::POST));
        $this->assertFalse(Method::isIdempotent(Method::PATCH));
    }

    public function testUnknownMethodIsNeitherSafeNorIdempotent(): void
    {
        $this->assertFalse(Method::isSafe('QUERY'));
        $this->assertFalse(Method::isIdempotent('QUERY'));
        $this->assertFalse(Method::isSafe(''));
        $this->assertFalse(Method::isIdempotent(''));
    }

    public function testComparisonIsCaseSensitive(): void
    {
        $this->assertFalse(Method::isSafe('get'));
        $this->assertFalse(Method::isIdempotent('get'));
        $this->assertFalse(Method::isSafe('trace'));
        $this->assertFalse(Method::isIdempotent('put'));
    }

    public function testTraceIsClassifiedButNotRoutable(): void
    {
        $this->assertSame('TRACE', Method::TRACE);
        $this->assertNotContains(Method::TRACE, Method::ALL);
    }
}
