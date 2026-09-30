<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\HttpContext;
use Kaly\Core\Kernel;
use Kaly\Http\ArraySession;
use Kaly\Http\ArraySessionProvider;
use Kaly\Http\ExceptionHandler;
use Kaly\Http\NativePhpSession;
use Kaly\Http\NativePhpSessionProvider;
use Kaly\Http\SessionInterface;
use Kaly\Http\SessionProviderInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Where the session of a request comes from and how it reaches the response:
 * a SessionProviderInterface held by the context, natively backed by default,
 * swappable per runtime.
 */
class SessionProviderTest extends TestCase
{
    private function provider(): SessionProviderInterface
    {
        return new ArraySessionProvider();
    }

    public function testDefaultSessionIsNative(): void
    {
        $ctx = new HttpContext(new ServerRequest('GET', '/'));

        $this->assertInstanceOf(NativePhpSession::class, $ctx->session());
        $this->assertSame($ctx->session(), $ctx->session(), 'the context owns one instance per cycle');
    }

    public function testProviderSessionIsUsedWhenGiven(): void
    {
        $ctx = new HttpContext(new ServerRequest('GET', '/'), $this->provider());

        $this->assertInstanceOf(ArraySession::class, $ctx->session());
        $this->assertSame($ctx->session(), $ctx->session(), 'the context owns one instance per cycle');
    }

    public function testNativeProviderBuildsNativeSessions(): void
    {
        $session = (new NativePhpSessionProvider())->create(new ServerRequest('GET', '/'));

        $this->assertInstanceOf(NativePhpSession::class, $session);
    }

    public function testCommitEmitsTheSessionCookie(): void
    {
        $provider = new ArraySessionProvider();
        $request = new ServerRequest('GET', '/');
        $session = $provider->create($request);
        $session->set('user', 'AUDIT-USER-A');

        $response = $provider->commit($session, $request, new Response());

        $this->assertStringContainsString('KALYSESSID=', $response->getHeaderLine('Set-Cookie'));
    }

    public function testArraySessionDestroyExpiresTheClientCookie(): void
    {
        $provider = new ArraySessionProvider();
        $request = (new ServerRequest('GET', '/'))->withCookieParams(['KALYSESSID' => 'stale']);
        $session = $provider->create($request);
        $session->destroy();

        $response = $provider->commit($session, $request, new Response());

        $cookie = $response->getHeaderLine('Set-Cookie');
        $this->assertStringStartsWith('KALYSESSID=', $cookie);
        $this->assertStringContainsString('Max-Age=0', $cookie);
    }

    public function testArraySessionIgnoresAnEmptyCookieValueAndGeneratesAnId(): void
    {
        $provider = new ArraySessionProvider();
        $request = (new ServerRequest('GET', '/'))->withCookieParams(['KALYSESSID' => '']);
        $session = $provider->create($request);
        $session->set('user', 'AUDIT-USER-A');

        $response = $provider->commit($session, $request, new Response());

        $cookie = $response->getHeaderLine('Set-Cookie');
        $this->assertStringStartsWith('KALYSESSID=', $cookie);
        $this->assertStringNotContainsString('KALYSESSID=;', $cookie);
    }

    public function testKernelPassesItsProviderToEveryCycle(): void
    {
        $seen = [];
        $handler = new class($seen) implements RequestHandlerInterface {
            /** @var list<SessionInterface> */
            public array $seen;

            /** @param list<SessionInterface> $seen */
            public function __construct(array &$seen)
            {
                $this->seen = &$seen;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen[] = HttpContext::from($request)->session();
                return new Response(200);
            }
        };
        $psr17 = new Psr17Factory();
        $kernel = new Kernel($handler, new ExceptionHandler($psr17, $psr17), sessionProvider: $this->provider());

        $kernel->handle(new ServerRequest('GET', '/'));
        $kernel->handle(new ServerRequest('GET', '/'));

        $this->assertCount(2, $seen);
        foreach ($seen as $session) {
            $this->assertInstanceOf(ArraySession::class, $session);
        }
        $this->assertNotSame($seen[0], $seen[1], 'one session per cycle');
    }

    public function testKernelWithoutProviderKeepsTheNativeDefault(): void
    {
        $seen = [];
        $handler = new class($seen) implements RequestHandlerInterface {
            /** @var list<SessionInterface> */
            public array $seen;

            /** @param list<SessionInterface> $seen */
            public function __construct(array &$seen)
            {
                $this->seen = &$seen;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen[] = HttpContext::from($request)->session();
                return new Response(200);
            }
        };
        $psr17 = new Psr17Factory();
        $kernel = new Kernel($handler, new ExceptionHandler($psr17, $psr17));

        $kernel->handle(new ServerRequest('GET', '/'));

        $this->assertCount(1, $seen);
        $this->assertInstanceOf(NativePhpSession::class, $seen[0]);
    }
}
