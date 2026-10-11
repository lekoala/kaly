<?php

declare(strict_types=1);

namespace Kaly\Tests;

use InvalidArgumentException;
use Kaly\Http\Cookie\SetCookieHeader;
use PHPUnit\Framework\TestCase;

class SetCookieHeaderTest extends TestCase
{
    public function testBuildsMinimalCookie(): void
    {
        $this->assertSame('a=b', SetCookieHeader::build('a', 'b', []));
    }

    public function testUrlEncodesNameAndValue(): void
    {
        $this->assertSame('a+b=c%3Bd', SetCookieHeader::build('a b', 'c;d', []));
    }

    public function testExpiredCookie(): void
    {
        $header = SetCookieHeader::build('auth', 'AUDIT', [], true);
        $this->assertStringContainsString('auth=AUDIT', $header);
        $this->assertStringContainsString('Max-Age=0', $header);
        $this->assertStringContainsString('Expires=Thu, 01 Jan 1970', $header);
    }

    public function testLifetimeBecomesExpiry(): void
    {
        $header = SetCookieHeader::build('auth', 'NEW', ['lifetime' => 3600, 'path' => '/app']);
        $this->assertStringContainsString('Max-Age=3600', $header);
        $this->assertStringContainsString('Path=/app', $header);
        $this->assertStringNotContainsString('2083', $header);
    }

    public function testSameSiteIsNormalized(): void
    {
        $header = SetCookieHeader::build('a', 'b', ['samesite' => 'lax']);
        $this->assertStringContainsString('; SameSite=Lax', $header);

        $header = SetCookieHeader::build('a', 'b', ['samesite' => 'bogus']);
        $this->assertStringNotContainsString('SameSite', $header);
    }

    public function testFlags(): void
    {
        $header = SetCookieHeader::build('a', 'b', [
            'domain' => 'example.test',
            'secure' => true,
            'httponly' => true,
            'partitioned' => true,
        ]);
        $this->assertStringContainsString('; Domain=example.test', $header);
        $this->assertStringContainsString('; Secure', $header);
        $this->assertStringContainsString('; HttpOnly', $header);
        $this->assertStringContainsString('; Partitioned', $header);
    }

    public function testDomainAndPathCannotInjectAttributes(): void
    {
        $this->assertSame('a=b; Domain=example.test; Path=/app', SetCookieHeader::build('a', 'b', [
            'domain' => 'example.test',
            'path' => '/app',
        ]));

        $invalid = [['domain' => 'example.test; Secure'], ['path' => "/\r\nSet-Cookie: x=y"], ['path' => "/a\0"]];
        $refused = 0;
        foreach ($invalid as $params) {
            try {
                SetCookieHeader::build('a', 'b', $params);
            } catch (InvalidArgumentException) {
                $refused++;
            }
        }
        $this->assertSame(count($invalid), $refused);
    }
}
