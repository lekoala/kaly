<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\HttpContext;
use Kaly\Core\Kernel;
use Kaly\Http\ExceptionHandler;
use Kaly\Http\Session\ArraySession;
use Kaly\Http\Session\ArraySessionProvider;
use Kaly\Http\Session\NativePhpSession;
use Kaly\Http\Session\NativePhpSessionProvider;
use Kaly\Http\Session\SessionInterface;
use Kaly\Http\Session\SessionProviderInterface;
use LogicException;
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

    public function testContextWithoutProviderRefusesToCreateASession(): void
    {
        $ctx = new HttpContext(new ServerRequest('GET', '/'));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No session provider is configured');
        $ctx->session();
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

        $provider->persist($session);
        $response = $provider->applyToResponse($session, $request, new Response());

        $this->assertStringContainsString('KALYSESSID=', $response->getHeaderLine('Set-Cookie'));
    }

    public function testArraySessionDestroyExpiresTheClientCookie(): void
    {
        $provider = new ArraySessionProvider();
        $request = (new ServerRequest('GET', '/'))->withCookieParams(['KALYSESSID' => 'stale']);
        $session = $provider->create($request);
        $session->destroy();

        $provider->persist($session);
        $response = $provider->applyToResponse($session, $request, new Response());

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

        $provider->persist($session);
        $response = $provider->applyToResponse($session, $request, new Response());

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

    public function testKernelWithoutProviderCannotCreateASession(): void
    {
        $failures = [];
        $handler = new class($failures) implements RequestHandlerInterface {
            /** @var list<LogicException> */
            public array $failures;

            /** @param list<LogicException> $failures */
            public function __construct(array &$failures)
            {
                $this->failures = &$failures;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                try {
                    HttpContext::from($request)->session();
                } catch (LogicException $e) {
                    $this->failures[] = $e;
                }
                return new Response(200);
            }
        };
        $psr17 = new Psr17Factory();
        $kernel = new Kernel($handler, new ExceptionHandler($psr17, $psr17));

        $kernel->handle(new ServerRequest('GET', '/'));

        $this->assertCount(1, $failures);
        $this->assertSame('No session provider is configured', $failures[0]->getMessage());
    }
}
