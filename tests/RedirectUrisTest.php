<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http\RedirectException;
use Kaly\Router\RedirectUris;
use Nyholm\Psr7\ServerRequest as BaseServerRequest;
use PHPUnit\Framework\TestCase;

class RedirectUrisTest extends TestCase
{
    public function testEnsureTrailingSlashRedirectsWhenMissing(): void
    {
        $request = new BaseServerRequest('GET', 'https://example.test/foo');

        try {
            RedirectUris::ensureTrailingSlash($request, true);
            $this->fail('A redirect was expected');
        } catch (RedirectException $e) {
            $this->assertSame('https://example.test/foo/', $e->getUrl());
        }
    }

    public function testEnsureTrailingSlashPassesWhenPresent(): void
    {
        $request = new BaseServerRequest('GET', 'https://example.test/foo/');

        // No redirect means no exception
        RedirectUris::ensureTrailingSlash($request, true);
        $this->assertTrue(true);
    }

    public function testEnsureNoTrailingSlashRedirectsWhenPresent(): void
    {
        $request = new BaseServerRequest('GET', 'https://example.test/foo/');

        try {
            RedirectUris::ensureTrailingSlash($request, false);
            $this->fail('A redirect was expected');
        } catch (RedirectException $e) {
            $this->assertSame('https://example.test/foo', $e->getUrl());
        }
    }

    public function testReplaceSegmentKeepsTrailingSlashPolicy(): void
    {
        $request = new BaseServerRequest('GET', 'https://example.test/en/foo/');

        $uri = RedirectUris::replaceSegment($request, 'en', '', true);
        $this->assertSame('/foo/', $uri->getPath());

        $uri = RedirectUris::replaceSegment($request, 'en', '', false);
        $this->assertSame('/foo', $uri->getPath());
    }

    public function testReplaceSegmentWithReplacement(): void
    {
        $request = new BaseServerRequest('GET', 'https://example.test/MyPart/');

        $uri = RedirectUris::replaceSegment($request, 'MyPart', 'my-part', true);
        $this->assertSame('/my-part/', $uri->getPath());
    }
}
