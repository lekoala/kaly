<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http\Cookies;
use Kaly\Http\Session;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest as BaseServerRequest;
use PHPUnit\Framework\TestCase;

class CookiesTest extends TestCase
{
    /**
     * @param array<string,string> $cookieParams
     */
    private function cookies(array $cookieParams): Cookies
    {
        return new Cookies((new BaseServerRequest('GET', '/'))->withCookieParams($cookieParams));
    }

    public function testCookiesInheritTheConfiguredBaseline(): void
    {
        Session::configureExtra(['httponly' => true, 'samesite' => 'Strict']);
        try {
            $cookies = $this->cookies([]);
            $cookies->set('theme', 'dark');
            $header = $cookies->addToResponse(new Response())->getHeaderLine('Set-Cookie');

            // Application cookies share the session cookie baseline rather than
            // whatever php.ini happens to hold
            $this->assertStringContainsString('theme=dark', $header);
            $this->assertStringContainsString('; HttpOnly', $header);
            $this->assertStringContainsString('; SameSite=Strict', $header);
        } finally {
            Session::configureExtra(['httponly' => true, 'samesite' => 'Lax']);
        }
    }

    public function testGetChangesReportsRemoval(): void
    {
        $cookies = $this->cookies(['auth' => 'AUDIT']);
        $cookies->remove('auth');
        $this->assertSame(['auth' => ['AUDIT', null]], $cookies->getChanges());
    }

    public function testRemoveEmitsExpiredCookie(): void
    {
        $cookies = $this->cookies(['auth' => 'AUDIT']);
        $cookies->remove('auth');
        $header = $cookies->addToResponse(new Response())->getHeaderLine('Set-Cookie');

        $this->assertNotSame('', $header);
        $this->assertStringContainsString('auth=', $header);
        $this->assertStringContainsString('Max-Age=0', $header);
        $this->assertStringContainsString('Expires=Thu, 01 Jan 1970', $header);
    }

    public function testEmptyStringAlsoDeletes(): void
    {
        $cookies = $this->cookies(['auth' => 'AUDIT']);
        $cookies->set('auth', '');
        $header = $cookies->addToResponse(new Response())->getHeaderLine('Set-Cookie');

        $this->assertStringContainsString('Max-Age=0', $header);
        $this->assertStringContainsString('Expires=Thu, 01 Jan 1970', $header);
    }

    public function testChangedValueEmitsCookieWithLifetime(): void
    {
        $cookies = $this->cookies(['auth' => 'OLD']);
        $cookies->setWithParams('auth', 'NEW', ['lifetime' => 3600, 'path' => '/app', 'samesite' => 'Lax']);
        $header = $cookies->addToResponse(new Response())->getHeaderLine('Set-Cookie');

        $this->assertStringContainsString('auth=NEW', $header);
        $this->assertStringContainsString('Path=/app', $header);
        $this->assertStringContainsString('SameSite=Lax', $header);
        $this->assertStringContainsString('Max-Age=3600', $header);
        // The lifetime must not be treated as an absolute timestamp
        $this->assertStringNotContainsString('2083', $header);
    }

    public function testClearDeletesAllOriginalCookies(): void
    {
        $cookies = $this->cookies(['a' => '1', 'b' => '2']);
        $cookies->clear();
        $headers = $cookies->addToResponse(new Response())->getHeader('Set-Cookie');

        $this->assertCount(2, $headers);
        foreach ($headers as $header) {
            $this->assertStringContainsString('Max-Age=0', $header);
        }
    }

    public function testUnchangedCookiesAreNotEmitted(): void
    {
        $cookies = $this->cookies(['auth' => 'AUDIT']);
        $response = $cookies->addToResponse(new Response());
        $this->assertSame([], $response->getHeader('Set-Cookie'));
    }
}
