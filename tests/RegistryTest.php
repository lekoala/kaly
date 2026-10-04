<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Fiber;
use Kaly\Core\HttpContext;
use Kaly\Core\Middleware\Band;
use Kaly\Core\Middleware\OutgoingInterface;
use Kaly\Core\Middleware\Registry;
use Kaly\Core\Middleware\Runner;
use Kaly\Tests\Mocks\TestMiddleware;
use LogicException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RegistryTest extends TestCase
{
    public function testRunningBandKeepsItsEntriesAcrossRegistryChanges(): void
    {
        $log = [];
        $registry = new Registry();
        $registry->incoming(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                Fiber::suspend();
                return $handler->handle($request);
            }
        });
        $registry->incoming($this->tracer('original', $log));
        $runner = new Runner(static fn(): ResponseInterface => new Response(200), null, $registry);
        $fiber = new Fiber(static fn(): ResponseInterface => $runner->handle(new ServerRequest('GET', '/first')));
        $fiber->start();

        $registry->clear(Band::Incoming)->incoming($this->tracer('replacement', $log));
        $next = $runner->handle(new ServerRequest('GET', '/next'));
        $fiber->resume();

        $this->assertTrue($fiber->isTerminated());
        $this->assertSame(['replacement', 'original'], $log);
        $this->assertSame(200, $next->getStatusCode());
        $first = $fiber->getReturn();
        assert($first instanceof ResponseInterface);
        $this->assertSame(200, $first->getStatusCode());
    }

    /**
     * @param array<string>|null $log
     */
    private function tracer(string $name, ?array &$log): MiddlewareInterface
    {
        return new class($name, $log) implements MiddlewareInterface {
            /**
             * @param array<string>|null $log
             */
            public function __construct(
                private string $name,
                private ?array &$log,
            ) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                if ($this->log !== null) {
                    $this->log[] = $this->name;
                }
                return $handler->handle($request);
            }
        };
    }

    public function testBandsAreIndependent(): void
    {
        $registry = new Registry();
        $registry->incoming(TestMiddleware::class);

        $this->assertCount(1, $registry->band(Band::Incoming));
        $this->assertCount(0, $registry->band(Band::Routed));

        $this->assertTrue($registry->has(TestMiddleware::class));
        $this->assertTrue($registry->has(TestMiddleware::class, Band::Incoming));
        $this->assertFalse($registry->has(TestMiddleware::class, Band::Routed));
    }

    public function testPriorityOrdersInsideABandAndRegistrationBreaksTies(): void
    {
        $log = [];
        $registry = new Registry();
        $registry->incoming($this->tracer('late', $log), priority: 200);
        $registry->incoming($this->tracer('first', $log), priority: -10);
        $registry->incoming($this->tracer('same-a', $log));
        $registry->incoming($this->tracer('same-b', $log));

        $runner = new Runner(static fn(): ResponseInterface => new Response(200), null, $registry, Band::Incoming);
        $runner->handle(new ServerRequest('GET', '/'));

        $this->assertSame(['first', 'same-a', 'same-b', 'late'], $log);
    }

    public function testEntriesAddedAfterASortAreStillOrdered(): void
    {
        $log = [];
        $registry = new Registry();
        $registry->incoming($this->tracer('b', $log), priority: 100);

        // Sorting the band caches it, adding must invalidate that cache
        $this->assertCount(1, $registry->band(Band::Incoming));
        $registry->incoming($this->tracer('a', $log), priority: 50);

        $runner = new Runner(static fn(): ResponseInterface => new Response(200), null, $registry, Band::Incoming);
        $runner->handle(new ServerRequest('GET', '/'));

        $this->assertSame(['a', 'b'], $log);
    }

    public function testClearRemovesABand(): void
    {
        $registry = new Registry();
        $registry->incoming(TestMiddleware::class);
        $registry->routed(TestMiddleware::class);

        $registry->clear(Band::Incoming);
        $this->assertFalse($registry->has(TestMiddleware::class, Band::Incoming));
        $this->assertTrue($registry->has(TestMiddleware::class, Band::Routed));

        $registry->clear();
        $this->assertFalse($registry->has(TestMiddleware::class));
    }

    public function testConditionReceivesTheContext(): void
    {
        $seen = null;
        $registry = new Registry();
        $registry->incoming(new TestMiddleware(), when: static function (HttpContext $ctx) use (&$seen): bool {
            $seen = $ctx;
            return false;
        });

        $runner = new Runner(
            static function (ServerRequestInterface $request): ResponseInterface {
                $value = $request->getAttribute(TestMiddleware::DEFAULT_ATTR);
                return new Response(200, [], is_string($value) ? $value : '');
            },
            null,
            $registry,
            Band::Incoming,
        );
        $response = $runner->handle(new ServerRequest('GET', '/'));

        $this->assertInstanceOf(HttpContext::class, $seen);
        $this->assertSame('', (string) $response->getBody(), 'a false condition skips the middleware');
        $this->assertSame([], $seen->middlewares(), 'a skipped middleware is not marked as executed');
    }

    public function testRunnerTracksExecutedMiddlewaresAndResponse(): void
    {
        $registry = new Registry();
        $registry->incoming(new TestMiddleware());

        $runner = new Runner(static fn(): ResponseInterface => new Response(204), null, $registry, Band::Incoming);

        $request = new ServerRequest('GET', '/');
        $ctx = new HttpContext($request);
        $response = $runner->handle($ctx->bind($request));

        $this->assertSame([TestMiddleware::class], $ctx->middlewares());
        $this->assertFalse($ctx->hasResponse(), 'the runner does not mirror the response during the unwind');
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testContextSurvivesAMiddlewareBuildingABrandNewRequest(): void
    {
        $registry = new Registry();
        $registry->incoming(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                // A third party middleware is free to hand over another request
                return $handler->handle(new ServerRequest('GET', '/rebuilt'));
            }
        });

        $runner = new Runner(
            static fn(ServerRequestInterface $request): ResponseInterface => new Response(
                200,
                [],
                HttpContext::from($request)->request()->getUri()->getPath(),
            ),
            null,
            $registry,
            Band::Incoming,
        );

        $request = new ServerRequest('GET', '/');
        $ctx = new HttpContext($request);
        $response = $runner->handle($ctx->bind($request));

        $this->assertSame('/rebuilt', (string) $response->getBody());
        $this->assertSame('/rebuilt', $ctx->request()->getUri()->getPath());
    }

    public function testRequestBandRejectsAnOutgoingMiddleware(): void
    {
        $registry = new Registry();
        $registry->add(Band::Incoming, new class implements OutgoingInterface {
            public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
            {
                return $response;
            }
        });

        $runner = new Runner(static fn(): ResponseInterface => new Response(200), null, $registry, Band::Incoming);

        $this->expectException(LogicException::class);
        $runner->handle(new ServerRequest('GET', '/'));
    }
}
