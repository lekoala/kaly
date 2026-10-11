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
            $this->assertSame('/foo/', $e->getUrl());
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
            $this->assertSame('/foo', $e->getUrl());
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

    public function testLocationIsRelativeAndKeepsTheQuery(): void
    {
        // The Host is client input: never reflect it in a cacheable redirect
        $request = new BaseServerRequest('GET', 'http://evil.example:8080/Index/x?a=1', ['Host' => 'evil.example']);

        $uri = RedirectUris::replaceSegment($request, 'Index', 'index');
        $this->assertSame('/index/x?a=1', (string) $uri);

        try {
            RedirectUris::ensureTrailingSlash($request, TrailingSlash::Add);
            $this->fail('A redirect was expected');
        } catch (RedirectException $e) {
            $this->assertSame('/Index/x/?a=1', $e->getUrl());
        }
    }

    public function testLocationNeverBecomesANetworkPathReference(): void
    {
        $request = new BaseServerRequest('GET', 'https://example.test//evil.test/Index/');

        $uri = RedirectUris::replaceSegment($request, 'Index', 'index', TrailingSlash::Add);
        $this->assertSame('/evil.test/index/', (string) $uri);

        try {
            RedirectUris::ensureTrailingSlash(new BaseServerRequest('GET', 'https://example.test//evil.test'), TrailingSlash::Add);
            $this->fail('A redirect was expected');
        } catch (RedirectException $e) {
            $this->assertSame('/evil.test/', $e->getUrl());
        }
    }
}
