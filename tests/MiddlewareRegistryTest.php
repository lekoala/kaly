<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\HttpContext;
use Kaly\Middleware\MiddlewareBand;
use Kaly\Middleware\MiddlewareRegistry;
use Kaly\Middleware\MiddlewareRunner;
use Kaly\Tests\Mocks\TestMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class MiddlewareRegistryTest extends TestCase
{
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
                $this->log[] = $this->name;
                return $handler->handle($request);
            }
        };
    }

    public function testBandsAreIndependent(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->incoming(TestMiddleware::class);

        $this->assertCount(1, $registry->band(MiddlewareBand::Incoming));
        $this->assertCount(0, $registry->band(MiddlewareBand::Routed));

        $this->assertTrue($registry->has(TestMiddleware::class));
        $this->assertTrue($registry->has(TestMiddleware::class, MiddlewareBand::Incoming));
        $this->assertFalse($registry->has(TestMiddleware::class, MiddlewareBand::Routed));
    }

    public function testPriorityOrdersInsideABandAndRegistrationBreaksTies(): void
    {
        $log = [];
        $registry = new MiddlewareRegistry();
        $registry->incoming($this->tracer('late', $log), priority: 200);
        $registry->incoming($this->tracer('first', $log), priority: -10);
        $registry->incoming($this->tracer('same-a', $log));
        $registry->incoming($this->tracer('same-b', $log));

        $runner = new MiddlewareRunner(static fn(): ResponseInterface => new Response(200), null, $registry, MiddlewareBand::Incoming);
        $runner->handle(new ServerRequest('GET', '/'));

        $this->assertSame(['first', 'same-a', 'same-b', 'late'], $log);
    }

    public function testEntriesAddedAfterASortAreStillOrdered(): void
    {
        $log = [];
        $registry = new MiddlewareRegistry();
        $registry->incoming($this->tracer('b', $log), priority: 100);

        // Sorting the band caches it, adding must invalidate that cache
        $this->assertCount(1, $registry->band(MiddlewareBand::Incoming));
        $registry->incoming($this->tracer('a', $log), priority: 50);

        $runner = new MiddlewareRunner(static fn(): ResponseInterface => new Response(200), null, $registry, MiddlewareBand::Incoming);
        $runner->handle(new ServerRequest('GET', '/'));

        $this->assertSame(['a', 'b'], $log);
    }

    public function testClearRemovesABand(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->incoming(TestMiddleware::class);
        $registry->routed(TestMiddleware::class);

        $registry->clear(MiddlewareBand::Incoming);
        $this->assertFalse($registry->has(TestMiddleware::class, MiddlewareBand::Incoming));
        $this->assertTrue($registry->has(TestMiddleware::class, MiddlewareBand::Routed));

        $registry->clear();
        $this->assertFalse($registry->has(TestMiddleware::class));
    }

    public function testConditionReceivesTheContext(): void
    {
        $seen = null;
        $registry = new MiddlewareRegistry();
        $registry->incoming(new TestMiddleware(), when: static function (HttpContext $ctx) use (&$seen): bool {
            $seen = $ctx;
            return false;
        });

        $runner = new MiddlewareRunner(
            static fn(ServerRequestInterface $request): ResponseInterface => new Response(
                200,
                [],
                (string) $request->getAttribute(TestMiddleware::DEFAULT_ATTR),
            ),
            null,
            $registry,
            MiddlewareBand::Incoming,
        );
        $response = $runner->handle(new ServerRequest('GET', '/'));

        $this->assertInstanceOf(HttpContext::class, $seen);
        $this->assertSame('', (string) $response->getBody(), 'a false condition skips the middleware');
        $this->assertSame([], $seen->middlewares(), 'a skipped middleware is not marked as executed');
    }

    public function testRunnerTracksExecutedMiddlewaresAndResponse(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->incoming(new TestMiddleware());

        $runner = new MiddlewareRunner(static fn(): ResponseInterface => new Response(204), null, $registry, MiddlewareBand::Incoming);

        $request = new ServerRequest('GET', '/');
        $ctx = new HttpContext($request);
        $response = $runner->handle($ctx->bind($request));

        $this->assertSame([TestMiddleware::class], $ctx->middlewares());
        $this->assertFalse($ctx->hasResponse(), 'the runner does not mirror the response during the unwind');
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testContextSurvivesAMiddlewareBuildingABrandNewRequest(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->incoming(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                // A third party middleware is free to hand over another request
                return $handler->handle(new ServerRequest('GET', '/rebuilt'));
            }
        });

        $runner = new MiddlewareRunner(
            static fn(ServerRequestInterface $request): ResponseInterface => new Response(
                200,
                [],
                HttpContext::from($request)->request()->getUri()->getPath(),
            ),
            null,
            $registry,
            MiddlewareBand::Incoming,
        );

        $request = new ServerRequest('GET', '/');
        $ctx = new HttpContext($request);
        $response = $runner->handle($ctx->bind($request));

        $this->assertSame('/rebuilt', (string) $response->getBody());
        $this->assertSame('/rebuilt', $ctx->request()->getUri()->getPath());
    }
}
