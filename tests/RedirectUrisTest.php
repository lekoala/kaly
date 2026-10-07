<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http\Exception\RedirectException;
use Kaly\Router\RedirectUris;
use Kaly\Router\TrailingSlash;
use Nyholm\Psr7\ServerRequest as BaseServerRequest;
use PHPUnit\Framework\TestCase;

class RedirectUrisTest extends TestCase
{
    public function testEnsureTrailingSlashRedirectsWhenMissing(): void
    {
        $request = new BaseServerRequest('GET', 'https://example.test/foo');

        try {
            RedirectUris::ensureTrailingSlash($request, TrailingSlash::Add);
            $this->fail('A redirect was expected');
        } catch (RedirectException $e) {
            $this->assertSame('https://example.test/foo/', $e->getUrl());
        }
    }

    public function testEnsureTrailingSlashPassesWhenPresent(): void
    {
        $request = new BaseServerRequest('GET', 'https://example.test/foo/');

        // No redirect means no exception
        $this->expectNotToPerformAssertions();
        RedirectUris::ensureTrailingSlash($request, TrailingSlash::Add);
    }

    public function testEnsureNoTrailingSlashRedirectsWhenPresent(): void
    {
        $request = new BaseServerRequest('GET', 'https://example.test/foo/');

        try {
            RedirectUris::ensureTrailingSlash($request, TrailingSlash::Remove);
            $this->fail('A redirect was expected');
        } catch (RedirectException $e) {
            $this->assertSame('https://example.test/foo', $e->getUrl());
        }
    }

    public function testRemoveNeverRedirectsRoot(): void
    {
        $request = new BaseServerRequest('GET', 'https://example.test/');

        $this->expectNotToPerformAssertions();
        RedirectUris::ensureTrailingSlash($request, TrailingSlash::Remove);
    }

    public function testPreserveNeverRedirects(): void
    {
        $urls = ['https://example.test/foo', 'https://example.test/foo/', 'https://example.test/'];
        foreach ($urls as $url) {
            RedirectUris::ensureTrailingSlash(new BaseServerRequest('GET', $url), TrailingSlash::Preserve);
        }
        $this->assertCount(3, $urls);
    }

    public function testReplaceSegmentKeepsTrailingSlashPolicy(): void
    {
        $request = new BaseServerRequest('GET', 'https://example.test/en/foo/');

        $uri = RedirectUris::replaceSegment($request, 'en', '', TrailingSlash::Add);
        $this->assertSame('/foo/', $uri->getPath());

        $uri = RedirectUris::replaceSegment($request, 'en', '', TrailingSlash::Remove);
        $this->assertSame('/foo', $uri->getPath());

        $uri = RedirectUris::replaceSegment($request, 'en', '', TrailingSlash::Preserve);
        $this->assertSame('/foo/', $uri->getPath());
    }

    public function testReplaceSegmentWithReplacement(): void
    {
        $request = new BaseServerRequest('GET', 'https://example.test/MyPart/');

        $uri = RedirectUris::replaceSegment($request, 'MyPart', 'my-part', TrailingSlash::Add);
        $this->assertSame('/my-part/', $uri->getPath());
    }
}
