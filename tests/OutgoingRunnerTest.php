<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\HttpContext;
use Kaly\Middleware\MiddlewareBand;
use Kaly\Middleware\MiddlewareRegistry;
use Kaly\Middleware\OutgoingMiddlewareInterface;
use Kaly\Middleware\OutgoingRunner;
use Kaly\Tests\Mocks\TestOutgoing;
use LogicException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class OutgoingRunnerTest extends TestCase
{
    private function transform(string $name, ?array &$log): OutgoingMiddlewareInterface
    {
        return new class($name, $log) implements OutgoingMiddlewareInterface {
            /**
             * @param array<string>|null $log
             */
            public function __construct(
                private string $name,
                private ?array &$log,
            ) {}

            public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
            {
                $this->log[] = $this->name;
                return $response->withHeader('X-Trace', $this->name);
            }
        };
    }

    private function context(): HttpContext
    {
        return new HttpContext(new ServerRequest('GET', '/'));
    }

    public function testOutgoingsRunByPriorityAndRegistrationOrder(): void
    {
        $log = [];
        $registry = new MiddlewareRegistry();
        $registry->outgoing($this->transform('late', $log), priority: 200);
        $registry->outgoing($this->transform('first', $log), priority: -10);
        $registry->outgoing($this->transform('same-a', $log));
        $registry->outgoing($this->transform('same-b', $log));

        $response = (new OutgoingRunner(null, $registry))->process(new Response(200), $this->context());

        $this->assertSame(['first', 'same-a', 'same-b', 'late'], $log);
        $this->assertSame('late', $response->getHeaderLine('X-Trace'), 'each middleware transforms the previous response');
    }

    public function testConditionReceivesTheCurrentResponse(): void
    {
        $run = [];
        $registry = new MiddlewareRegistry();
        // Turns the response into a 404 before the conditions below evaluate
        $registry->outgoing(new class implements OutgoingMiddlewareInterface {
            public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
            {
                return $response->withStatus(404);
            }
        });
        $registry->outgoing($this->transform('conditional', $run), when: static function (ResponseInterface $response): bool {
            return $response->getStatusCode() === 404;
        });
        $registry->outgoing($this->transform('skipped', $run), when: static function (ResponseInterface $response): bool {
            return $response->getStatusCode() === 200;
        });

        $response = (new OutgoingRunner(null, $registry))->process(new Response(200), $this->context());

        $this->assertSame(['conditional'], $run);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('conditional', $response->getHeaderLine('X-Trace'));
    }

    public function testFalseConditionSkipsAndDoesNotMark(): void
    {
        $ctx = $this->context();
        $registry = new MiddlewareRegistry();
        $registry->outgoing($this->transform('never', $log), when: static fn(): bool => false);

        $response = (new OutgoingRunner(null, $registry))->process(new Response(200), $ctx);

        $this->assertSame([], $ctx->middlewares());
        $this->assertSame('', $response->getHeaderLine('X-Trace'));
    }

    public function testClassStringIsResolvedFromTheContainer(): void
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return new TestOutgoing();
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        $registry = new MiddlewareRegistry();
        $registry->outgoing(TestOutgoing::class);

        $response = (new OutgoingRunner($container, $registry))->process(new Response(200), $this->context());

        $this->assertSame('done', $response->getHeaderLine(TestOutgoing::HEADER));
    }

    public function testAResolvedClassStringMustBeOutgoing(): void
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return new class implements MiddlewareInterface {
                    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                    {
                        return $handler->handle($request);
                    }
                };
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        $registry = new MiddlewareRegistry();
        $registry->outgoing('Some\Wrong\Outgoing\Class');

        $this->expectException(LogicException::class);
        (new OutgoingRunner($container, $registry))->process(new Response(200), $this->context());
    }

    public function testExecutedOutgoingsAreMarkedOnTheContext(): void
    {
        $ctx = $this->context();
        $registry = new MiddlewareRegistry();
        $registry->outgoing(new TestOutgoing());

        $response = (new OutgoingRunner(null, $registry))->process(new Response(200), $ctx);

        $this->assertSame([TestOutgoing::class], $ctx->middlewares());
        $this->assertTrue($ctx->hasMiddleware(TestOutgoing::class));
    }

    public function testOutgoingIsAThirdBandOfTheRegistry(): void
    {
        $registry = new MiddlewareRegistry();
        $registry->outgoing(TestOutgoing::class);

        $this->assertCount(1, $registry->band(MiddlewareBand::Outgoing));
        $this->assertCount(0, $registry->band(MiddlewareBand::Incoming));
        $this->assertCount(0, $registry->band(MiddlewareBand::Routed));
        $this->assertArrayHasKey('outgoing', $registry->toArray());
        $this->assertTrue($registry->has(TestOutgoing::class, MiddlewareBand::Outgoing));
    }
}
