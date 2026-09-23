<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http\CookiePolicy;
use Kaly\Http\Cookies;
use Kaly\Http\Session;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest as BaseServerRequest;
use PHPUnit\Framework\TestCase;

class CookiePolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        // Static application default: never leak between tests
        CookiePolicy::setDefault(new CookiePolicy(lifetime: 0, httpOnly: true, sameSite: 'Lax'));
        Session::configureExtra(['lifetime' => 0, 'httponly' => true, 'samesite' => 'Lax']);
    }

    public function testDefaultHoldsTheHistoricalBaseline(): void
    {
        $policy = CookiePolicy::default();

        $this->assertSame(0, $policy->lifetime);
        $this->assertTrue($policy->httpOnly);
        $this->assertSame('Lax', $policy->sameSite);
        $this->assertNull($policy->path);
        $this->assertNull($policy->domain);
        $this->assertNull($policy->secure);
        $this->assertNull($policy->partitioned);
    }

    public function testToArrayOnlyExposesExplicitEntries(): void
    {
        $this->assertSame(['lifetime' => 0, 'httponly' => true, 'samesite' => 'Lax'], CookiePolicy::default()->toArray());
        $this->assertSame(['path' => '/app', 'secure' => true], (new CookiePolicy(path: '/app', secure: true))->toArray());
    }

    public function testWithKeepsUnmentionedValues(): void
    {
        $derived = CookiePolicy::default()->with(sameSite: 'Strict', secure: true);

        $this->assertSame('Strict', $derived->sameSite);
        $this->assertTrue($derived->secure);
        $this->assertSame(0, $derived->lifetime);
        // The default itself is untouched: the policy is immutable
        $this->assertSame('Lax', CookiePolicy::default()->sameSite);
    }

    public function testSessionConfigureExtraFeedsTheSharedPolicy(): void
    {
        Session::configureExtra(['lifetime' => 1234, 'samesite' => 'Strict']);

        $policy = CookiePolicy::default();
        $this->assertSame(1234, $policy->lifetime);
        $this->assertSame('Strict', $policy->sameSite);

        // Cookies see it without depending on Session anymore
        $cookies = new Cookies(new BaseServerRequest('GET', '/'));
        $cookies->set('theme', 'dark');
        $header = $cookies->addToResponse(new Response())->getHeaderLine('Set-Cookie');
        $this->assertStringContainsString('Max-Age=1234', $header);
        $this->assertStringContainsString('; SameSite=Strict', $header);

        // And the historical static accessor agrees
        $this->assertSame(1234, Session::getCookieDefaults()['lifetime']);
    }

    public function testInjectedPolicyWinsOverTheDefault(): void
    {
        Session::configureExtra(['samesite' => 'Strict']);

        $cookies = new Cookies(new BaseServerRequest('GET', '/'), new CookiePolicy(lifetime: 0, httpOnly: true, sameSite: 'Lax'));
        $cookies->set('theme', 'dark');
        $header = $cookies->addToResponse(new Response())->getHeaderLine('Set-Cookie');

        $this->assertStringContainsString('; SameSite=Lax', $header);
    }
}
