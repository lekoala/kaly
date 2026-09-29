<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\HttpContext;
use Kaly\Core\Kernel;
use Kaly\Http\ArraySession;
use Kaly\Http\ExceptionHandler;
use Kaly\Http\NativePhpSession;
use Kaly\Http\NativePhpSessionFactory;
use Kaly\Http\SessionFactoryInterface;
use Kaly\Http\SessionInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Where the session of a request comes from: a SessionFactoryInterface held
 * by the context, natively backed by default, swappable per runtime.
 */
class SessionFactoryTest extends TestCase
{
    private function factory(): SessionFactoryInterface
    {
        return new class implements SessionFactoryInterface {
            public function create(ServerRequestInterface $request): SessionInterface
            {
                return new ArraySession([], $request);
            }
        };
    }

    public function testDefaultSessionIsNative(): void
    {
        $ctx = new HttpContext(new ServerRequest('GET', '/'));

        $this->assertInstanceOf(NativePhpSession::class, $ctx->session());
        $this->assertSame($ctx->session(), $ctx->session(), 'the context owns one instance per cycle');
    }

    public function testFactorySessionIsUsedWhenGiven(): void
    {
        $ctx = new HttpContext(new ServerRequest('GET', '/'), $this->factory());

        $this->assertInstanceOf(ArraySession::class, $ctx->session());
        $this->assertSame($ctx->session(), $ctx->session(), 'the context owns one instance per cycle');
    }

    public function testNativeFactoryBuildsNativeSessions(): void
    {
        $session = (new NativePhpSessionFactory())->create(new ServerRequest('GET', '/'));

        $this->assertInstanceOf(NativePhpSession::class, $session);
    }

    public function testKernelPassesItsFactoryToEveryCycle(): void
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
        $kernel = new Kernel($handler, new ExceptionHandler($psr17, $psr17), sessionFactory: $this->factory());

        $kernel->handle(new ServerRequest('GET', '/'));
        $kernel->handle(new ServerRequest('GET', '/'));

        $this->assertCount(2, $seen);
        foreach ($seen as $session) {
            $this->assertInstanceOf(ArraySession::class, $session);
        }
        $this->assertNotSame($seen[0], $seen[1], 'one session per cycle');
    }

    public function testKernelWithoutFactoryKeepsTheNativeDefault(): void
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
