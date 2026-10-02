<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Router\RoutePath;
use PHPUnit\Framework\TestCase;

class RoutePathTest extends TestCase
{
    public function testSegments(): void
    {
        $this->assertSame([], RoutePath::segments('/'));
        $this->assertSame(['foo'], RoutePath::segments('/foo/'));
        $this->assertSame(['foo', 'bar'], RoutePath::segments('/foo//bar/'));
    }

    public function testJoin(): void
    {
        $this->assertSame('/', RoutePath::join(''));
        $this->assertSame('/foo', RoutePath::join('/', 'foo'));
        $this->assertSame('/foo/bar', RoutePath::join('/foo/', '/bar/'));
    }

    public function testTrailingSlash(): void
    {
        $this->assertSame('/', RoutePath::withoutTrailingSlash('/'));
        $this->assertSame('/foo', RoutePath::withoutTrailingSlash('/foo/'));
        $this->assertSame('/', RoutePath::withTrailingSlash('/'));
        $this->assertSame('/foo/', RoutePath::withTrailingSlash('/foo'));
    }
}
