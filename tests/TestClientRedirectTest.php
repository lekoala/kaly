<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Router\TrailingSlash;
use Kaly\Test\TestClient;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Reference resolution of redirect Locations: origin preservation, dot
 * segments and query/fragment handling.
 */
class TestClientRedirectTest extends TestCase
{
    private TestClient $client;
    private ReflectionMethod $resolve;

    protected function setUp(): void
    {
        $this->client = TestClient::for(App::create(__DIR__)->routing(TrailingSlash::Add, true)->boot());
        $this->resolve = new ReflectionMethod($this->client, 'resolveLocation');
        $this->resolve->setAccessible(true);
    }

    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    private function resolve(string $base, string $location): string
    {
        $resolved = $this->resolve->invoke($this->client, $base, $location);
        if (!is_string($resolved)) {
            $this->fail("resolveLocation('{$base}', '{$location}') did not return a string");
        }
        return $resolved;
    }

    public function testAbsolutePathKeepsTheOrigin(): void
    {
        $this->assertSame('https://good.example/middle', $this->resolve('https://good.example/start', '/middle'));
    }

    public function testRelativeReferenceKeepsTheOrigin(): void
    {
        $this->assertSame('https://good.example/a/c', $this->resolve('https://good.example/a/b', 'c'));
    }

    public function testRelativeBaseStaysRelative(): void
    {
        $this->assertSame('/target', $this->resolve('/a/source', '../target'));
    }

    public function testDotSegmentKeepsTheTrailingSlash(): void
    {
        $this->assertSame('https://good.example/a/b/', $this->resolve('https://good.example/a/b/page', '.'));
        $this->assertSame('https://good.example/a/', $this->resolve('https://good.example/a/b/page', '..'));
    }

    public function testRelativeFromADirectoryBase(): void
    {
        $this->assertSame('https://good.example/a/b/next', $this->resolve('https://good.example/a/b/', 'next'));
    }

    public function testBareQueryReplacesTheBaseQuery(): void
    {
        $this->assertSame('https://good.example/page?b=2', $this->resolve('https://good.example/page?a=1', '?b=2'));
    }

    public function testFragmentKeepsTheBaseQuery(): void
    {
        $this->assertSame('https://good.example/page?x=1#next', $this->resolve('https://good.example/page?x=1', '#next'));
        $this->assertSame('https://good.example/page#next', $this->resolve('https://good.example/page', '#next'));
    }

    public function testNetworkPathCrossOriginIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cross-origin');
        $this->resolve('http://good.example/x', '//evil.example/y');
    }
}
