<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http\ServerRequest;
use Kaly\Text\LocaleResolver;
use Nyholm\Psr7\ServerRequest as BaseServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

class LocaleResolverTest extends TestCase
{
    private function request(?string $acceptLanguage = null): ServerRequestInterface
    {
        $request = new BaseServerRequest('GET', '/');
        if ($acceptLanguage !== null) {
            $request = $request->withHeader('Accept-Language', $acceptLanguage);
        }
        return new ServerRequest($request);
    }

    public function testRouteLocaleWins(): void
    {
        $resolver = new LocaleResolver('en', ['en', 'fr']);
        $request = $this->request('fr')->withAttribute(LocaleResolver::ATTR_LOCALE_REQUEST, 'fr');

        $this->assertSame('en', $resolver->resolve($request, 'en'));
    }

    public function testMiddlewareAttributeComesBeforeHeaders(): void
    {
        $resolver = new LocaleResolver('en', ['en', 'fr']);
        $request = $this->request('fr')->withAttribute(LocaleResolver::ATTR_LOCALE_REQUEST, 'en');

        $this->assertSame('en', $resolver->resolve($request));
    }

    public function testNegotiatesAcceptLanguage(): void
    {
        $resolver = new LocaleResolver('en', ['en', 'fr']);

        $this->assertSame('fr', $resolver->resolve($this->request('fr')));
        // en-US matches the supported en
        $this->assertSame('en', $resolver->resolve($this->request('en-US')));
    }

    public function testFallsBackToDefault(): void
    {
        $resolver = new LocaleResolver('en', ['en', 'fr']);

        // No preference at all
        $this->assertSame('en', $resolver->resolve($this->request()));
        // Wildcard is not a usable preference
        $this->assertSame('en', $resolver->resolve($this->request('*')));
        // Not in the allowed list
        $this->assertSame('en', $resolver->resolve($this->request('de')));
        // Imposed but not allowed
        $this->assertSame('en', $resolver->resolve($this->request(), 'de'));
        // Malformed
        $this->assertSame('en', $resolver->resolve($this->request(), '!!'));
    }

    public function testWithoutAllowedLocalesAnyParseableLocalePasses(): void
    {
        $resolver = new LocaleResolver('en');

        $this->assertSame('de', $resolver->resolve($this->request('de')));
        $this->assertSame('zh-Hant-TW', $resolver->resolve($this->request(), 'zh-Hant-TW'));
        $this->assertSame('en', $resolver->resolve($this->request(), '!!'));
    }

    public function testNoStateLeaksBetweenRequests(): void
    {
        $resolver = new LocaleResolver('en', ['en', 'fr']);

        $this->assertSame('fr', $resolver->resolve($this->request('fr')));
        // A later request without preference is not affected by the previous one
        $this->assertSame('en', $resolver->resolve($this->request()));
    }

    public function testApplySetsTheAttribute(): void
    {
        $resolver = new LocaleResolver('en', ['en', 'fr']);

        $request = $resolver->apply($this->request());
        $this->assertSame('en', $request->getAttribute(LocaleResolver::ATTR_LOCALE_REQUEST));

        $request = $resolver->apply($this->request('fr'));
        $this->assertSame('fr', $request->getAttribute(LocaleResolver::ATTR_LOCALE_REQUEST));
    }

    public function testWorksWithAPlainPsr7Request(): void
    {
        $resolver = new LocaleResolver('en', ['en', 'fr']);
        $request = (new BaseServerRequest('GET', '/'))->withHeader('Accept-Language', 'fr');

        $this->assertSame('fr', $resolver->resolve($request));
    }
}
