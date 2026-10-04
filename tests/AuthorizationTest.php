<?php

declare(strict_types=1);

namespace Kaly\Tests;

use InvalidArgumentException;
use Kaly\Http\Authorization;
use Kaly\Http\Exception\UnauthorizedException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Authorization is pure HTTP parsing: one header value, a token scheme,
 * strict Basic decoding, b64token Bearer. Credential validation itself
 * belongs to the application.
 */
class AuthorizationTest extends TestCase
{
    private function request(?string $header = null, bool $twice = false): ServerRequestInterface
    {
        $request = new ServerRequest('GET', '/');

        if ($header === null) {
            return $request;
        }

        $request = $request->withHeader('Authorization', $header);

        return $twice ? $request->withAddedHeader('Authorization', 'Bearer second') : $request;
    }

    public function testMissingOrEmptyHeaderIsNull(): void
    {
        $this->assertNull(Authorization::from($this->request()));
        $this->assertNull(Authorization::from($this->request('   ')));
        $this->assertNull(Authorization::from($this->request('Bearer')));
    }

    public function testMultipleHeadersAreRejected(): void
    {
        $this->assertNull(Authorization::from($this->request('Bearer first', true)));
    }

    public function testSchemeIsCaseInsensitive(): void
    {
        $authorization = Authorization::from($this->request('BASIC ' . base64_encode('al:pw')));

        $this->assertNotNull($authorization);
        $this->assertSame('basic', $authorization->scheme());
        // RFC 7617 test vector, not a real secret
        // @mago-expect lint:no-literal-password
        $this->assertSame(['username' => 'al', 'password' => 'pw'], $authorization->basic());
    }

    public function testBasicSplitsOnTheFirstColon(): void
    {
        $authorization = Authorization::from($this->request('Basic ' . base64_encode('al:pw:with:colons')));

        $this->assertNotNull($authorization);
        // RFC 7617 test vector, not a real secret
        // @mago-expect lint:no-literal-password
        $this->assertSame(['username' => 'al', 'password' => 'pw:with:colons'], $authorization->basic());
        $this->assertNull($authorization->bearer());
    }

    public function testBasicRejectsMissingColonAndBadBase64(): void
    {
        $this->assertNull(Authorization::from($this->request('Basic ' . base64_encode('nocolon')))?->basic());
        $this->assertNull(Authorization::from($this->request('Basic !!!'))?->basic());
    }

    public function testBasicRejectsControlCharacters(): void
    {
        $authorization = Authorization::from($this->request('Basic ' . base64_encode("al\x00:pw")));

        $this->assertNotNull($authorization);
        $this->assertNull($authorization->basic());
    }

    public function testBearerStaysOpaqueButChecked(): void
    {
        $valid = Authorization::from($this->request('Bearer abcDEF-123._~+/=='));

        $this->assertNotNull($valid);
        $this->assertSame('abcDEF-123._~+/==', $valid->bearer());
        $this->assertNull($valid->basic());

        $this->assertNull(Authorization::from($this->request('Bearer not a token!'))?->bearer());
        $this->assertNull(Authorization::from($this->request('Bearer ***'))?->bearer());
    }

    public function testUnknownSchemeKeepsTheObject(): void
    {
        $authorization = Authorization::from($this->request('Digest username="al"'));

        $this->assertNotNull($authorization);
        $this->assertSame('digest', $authorization->scheme());
        $this->assertNull($authorization->basic());
        $this->assertNull($authorization->bearer());
    }

    public function testUnauthorizedCarriesItsChallenge(): void
    {
        $exception = new UnauthorizedException('Bearer');

        $this->assertSame(401, $exception->status());
        $this->assertSame(['WWW-Authenticate' => 'Bearer'], $exception->getResponseHeaders());
    }

    public function testUnauthorizedRejectsAnEmptyChallenge(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UnauthorizedException('');
    }

    public function testUnauthorizedRejectsABlankChallenge(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new UnauthorizedException('   ');
    }
}
