<?php

declare(strict_types=1);

namespace Kaly\Tests;

use InvalidArgumentException;
use Kaly\Http\Cookie\CookiePolicy;
use Kaly\Http\Cookie\Cookies;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest as BaseServerRequest;
use PHPUnit\Framework\TestCase;

class CookiePolicyTest extends TestCase
{
    public function testBaselineHoldsTheHistoricalValues(): void
    {
        $policy = CookiePolicy::baseline();

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
        $this->assertSame(['lifetime' => 0, 'httponly' => true, 'samesite' => 'Lax'], CookiePolicy::baseline()->toArray());
        $this->assertSame(['path' => '/app', 'secure' => true], (new CookiePolicy(path: '/app', secure: true))->toArray());
    }

    public function testWithKeepsUnmentionedValues(): void
    {
        $baseline = CookiePolicy::baseline();
        $derived = $baseline->with(sameSite: 'Strict', secure: true);

        $this->assertSame('Strict', $derived->sameSite);
        $this->assertTrue($derived->secure);
        $this->assertSame(0, $derived->lifetime);
        // The baseline itself is untouched: the policy is immutable
        $this->assertSame('Lax', $baseline->sameSite);
    }

    public function testSameSiteIsCanonicalisedSoItIsNeverDropped(): void
    {
        foreach (['Strict', 'strict', 'STRICT'] as $spelling) {
            $policy = new CookiePolicy(sameSite: $spelling);
            $this->assertSame('Strict', $policy->sameSite, "spelling '{$spelling}'");
        }

        // The policy holds a mode the Set-Cookie builder can actually emit
        $request = new BaseServerRequest('GET', '/');
        $cookies = new Cookies($request, new CookiePolicy(sameSite: 'STRICT'));
        $cookies->set('theme', 'dark');
        $header = $cookies->addToResponse(new Response())->getHeaderLine('Set-Cookie');
        $this->assertStringContainsString('; SameSite=Strict', $header);
    }

    public function testAnUnknownSameSiteIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CookiePolicy(sameSite: 'Whatever');
    }

    public function testPoliciesArePerInstanceNeverGlobal(): void
    {
        $request = new BaseServerRequest('GET', '/');

        $strict = new Cookies($request, new CookiePolicy(lifetime: 1234, httpOnly: true, sameSite: 'Strict'));
        $strict->set('theme', 'dark');
        $header = $strict->addToResponse(new Response())->getHeaderLine('Set-Cookie');
        $this->assertStringContainsString('Max-Age=1234', $header);
        $this->assertStringContainsString('; SameSite=Strict', $header);

        // Another jar with another policy is unaffected
        $lax = new Cookies($request, new CookiePolicy(lifetime: 0, httpOnly: true, sameSite: 'Lax'));
        $lax->set('theme', 'dark');
        $laxHeader = $lax->addToResponse(new Response())->getHeaderLine('Set-Cookie');
        $this->assertStringContainsString('; SameSite=Lax', $laxHeader);
    }

    public function testInjectedPolicyWinsOverPhpIni(): void
    {
        $cookies = new Cookies(new BaseServerRequest('GET', '/'), new CookiePolicy(lifetime: 0, httpOnly: true, sameSite: 'Lax'));
        $cookies->set('theme', 'dark');
        $header = $cookies->addToResponse(new Response())->getHeaderLine('Set-Cookie');

        $this->assertStringContainsString('; SameSite=Lax', $header);
    }
}
