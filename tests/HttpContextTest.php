<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http\HttpContext;
use LogicException;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class HttpContextTest extends TestCase
{
    public function testBindAttachesTheContextToTheRequest(): void
    {
        $request = new ServerRequest('GET', '/');
        $ctx = new HttpContext($request);

        $bound = $ctx->bind($request);

        $this->assertSame($ctx, $bound->getAttribute(HttpContext::ATTRIBUTE));
        $this->assertSame($bound, $ctx->request, 'the context tracks the current request');
    }

    public function testBindIsIdempotent(): void
    {
        $ctx = new HttpContext(new ServerRequest('GET', '/'));
        $first = $ctx->bind($ctx->request);
        $second = $ctx->bind($first);

        $this->assertSame($first, $second, 'rebinding the same request creates no new instance');
    }

    public function testFromThrowsWhenNoContextIsAttached(): void
    {
        $this->expectException(LogicException::class);
        HttpContext::from(new ServerRequest('GET', '/'));
    }

    public function testTryFromReturnsNullWhenNoContextIsAttached(): void
    {
        $this->assertNull(HttpContext::tryFrom(new ServerRequest('GET', '/')));
    }

    public function testEnsureCreatesAndReusesTheContext(): void
    {
        $request = new ServerRequest('GET', '/');

        $ctx = HttpContext::ensure($request);
        $this->assertSame($ctx, HttpContext::from($ctx->request));

        // A brand new request derived from the bound one keeps the same context
        $derived = $ctx->request->withAttribute('foo', 'bar');
        $this->assertSame($ctx, HttpContext::ensure($derived));
        $this->assertSame('bar', $ctx->request->getAttribute('foo'));
    }

    public function testMiddlewaresAreTrackedInOrder(): void
    {
        $ctx = new HttpContext(new ServerRequest('GET', '/'));
        $this->assertSame([], $ctx->middlewares());

        $ctx->markMiddleware(LogicException::class);
        $ctx->markMiddleware(RuntimeException::class);

        $this->assertSame([LogicException::class, RuntimeException::class], $ctx->middlewares());
        $this->assertTrue($ctx->hasMiddleware(RuntimeException::class));
        $this->assertFalse($ctx->hasMiddleware(self::class));
    }

    public function testClientIpFallsBackWhenTheServerReportsNothing(): void
    {
        $ctx = new HttpContext(new ServerRequest('GET', '/'));
        $this->assertSame('0.0.0.0', $ctx->clientIp());

        $ctx = new HttpContext(new ServerRequest('GET', '/', [], null, '1.1', ['REMOTE_ADDR' => '10.0.0.1']));
        $this->assertSame('10.0.0.1', $ctx->clientIp());
    }

    public function testCallbackErrorsAreCollected(): void
    {
        $ctx = new HttpContext(new ServerRequest('GET', '/'));
        $this->assertSame([], $ctx->callbackErrors());

        $ex = new RuntimeException('broken callback');
        $ctx->addCallbackError($ex);

        $this->assertSame([$ex], $ctx->callbackErrors());
    }
}
