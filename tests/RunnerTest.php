<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\HttpContext;
use Kaly\Core\Middleware\Runner;
use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Test\PredefinedResponseHandler;
use Kaly\Tests\Mocks\TransactionInterface;
use LogicException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

class RunnerTest extends TestCase
{
    private function finalHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200, [], 'final');
            }
        };
    }

    public function testEmptyStackRunsFinalHandler(): void
    {
        $runner = new Runner($this->finalHandler());
        $response = $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame('final', (string) $response->getBody());
    }

    public function testHandleHonorsItsRequestEvenWithAnAttachedContext(): void
    {
        $ctx = new HttpContext(new ServerRequest('GET', '/'));
        $given = (new ServerRequest('GET', '/'))
            ->withAttribute(HttpContext::ATTRIBUTE, $ctx)
            ->withHeader('X-Given', 'yes');

        $seen = null;
        $runner = new Runner(function (ServerRequestInterface $request) use (&$seen): ResponseInterface {
            $seen = $request->getHeaderLine('X-Given');
            return new Response(200);
        });

        $runner->handle($given);

        $this->assertSame('yes', $seen, 'the request handed to handle() is authoritative');
    }

    public function testPsr15MiddlewareRunsAroundHandler(): void
    {
        $runner = new Runner($this->finalHandler());
        $runner->add(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $updated = $request->withAttribute('seen', true);
                $response = $handler->handle($updated);
                return $response->withHeader('X-Seen', $updated->getAttribute('seen') ? 'yes' : 'no');
            }
        });

        $response = $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame('yes', $response->getHeaderLine('X-Seen'));
        $this->assertSame('final', (string) $response->getBody());
    }

    public function testPsr15MiddlewareCanShortCircuit(): void
    {
        $runner = new Runner($this->finalHandler());
        $runner->add(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response(401, [], 'nope');
            }
        });

        $response = $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('nope', (string) $response->getBody());
    }

    public function testConditionSkipsMiddleware(): void
    {
        $runner = new Runner($this->finalHandler());
        $runner->add(
            new class implements MiddlewareInterface {
                public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                {
                    return new Response(418);
                }
            },
            when: static fn(): bool => false,
        );

        $response = $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testMiddlewareRunsBeforeAndAfter(): void
    {
        $events = [];
        $runner = new Runner(function () use (&$events): ResponseInterface {
            $events[] = 'inner';
            return new Response(200);
        });
        $runner->add(new class($events) implements MiddlewareInterface {
            /** @var list<string> */
            public array $events;

            /** @param list<string> $events */
            public function __construct(array &$events)
            {
                $this->events = &$events;
            }

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->events[] = 'before';
                try {
                    return $handler->handle($request);
                } finally {
                    $this->events[] = 'after';
                }
            }
        });

        $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame(['before', 'inner', 'after'], $events);
    }

    public function testAfterSeesTheRequestTheMiddlewareItselfForwarded(): void
    {
        $runner = new Runner(fn(): ResponseInterface => new Response(200));
        $runner->add(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $forwarded = $request->withAttribute('tag', 'set-before');
                $response = $handler->handle($forwarded);
                $tag = $forwarded->getAttribute('tag');
                return $response->withHeader('X-Tag', is_string($tag) ? $tag : '');
            }
        });

        $response = $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame('set-before', $response->getHeaderLine('X-Tag'));
    }

    public function testShortCircuitSkipsBothInnerLayersAndAfterCode(): void
    {
        $ran = false;
        $runner = new Runner(function () use (&$ran): ResponseInterface {
            $ran = true;
            return new Response(200);
        });
        $runner->add(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                // Never calls $handler: nothing below runs, no after code either
                return new Response(401, [], 'denied');
            }
        });

        $response = $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('denied', (string) $response->getBody());
        $this->assertFalse($ran, 'the inner layers must not run');
    }

    public function testAnOuterAfterStillWrapsAShortCircuit(): void
    {
        $runner = new Runner(fn(): ResponseInterface => new Response(200));
        $runner->add(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);
                return $response->withHeader('X-Outer', 'ran');
            }
        });
        $runner->add(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response(401);
            }
        });

        $response = $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('ran', $response->getHeaderLine('X-Outer'));
    }

    public function testMiddlewareCanCatchADownstreamException(): void
    {
        $runner = new Runner(function (): ResponseInterface {
            throw new RuntimeException('boom');
        });
        $runner->add(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                try {
                    return $handler->handle($request);
                } catch (Throwable $e) {
                    return new Response(503, [], 'caught: ' . $e->getMessage());
                }
            }
        });

        $response = $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('caught: boom', (string) $response->getBody());
    }

    public function testAnUncaughtDownstreamExceptionStillEscapes(): void
    {
        $runner = new Runner(function (): ResponseInterface {
            throw new RuntimeException('boom');
        });
        $runner->add(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $runner->handle(new ServerRequest('GET', '/'));
    }

    public function testARecoveredExceptionStillRunsTheOuterAfter(): void
    {
        $runner = new Runner(function (): ResponseInterface {
            throw new RuntimeException('deep');
        });
        $runner->add(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);
                return $response->withHeader('X-Outer', 'ran');
            }
        });
        $runner->add(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                try {
                    return $handler->handle($request);
                } catch (Throwable $e) {
                    return new Response(503);
                }
            }
        });

        $response = $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('ran', $response->getHeaderLine('X-Outer'));
    }

    public function testTransactionCommitsOrRollsBack(): void
    {
        /** @var list<string> $events */
        $events = [];
        $transaction = new class($events) implements TransactionInterface {
            /** @var list<string> */
            public array $events;

            /** @param list<string> $events */
            public function __construct(array &$events)
            {
                $this->events = &$events;
            }

            public function commit(): void
            {
                $this->events[] = 'commit';
            }

            public function rollback(): void
            {
                $this->events[] = 'rollback';
            }
        };
        $runner = new Runner(fn(): ResponseInterface => new Response(200));
        $runner->add(new class($transaction) implements MiddlewareInterface {
            public function __construct(
                private TransactionInterface $transaction,
            ) {}

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                try {
                    $response = $handler->handle($request);
                    $this->transaction->commit();
                    return $response;
                } catch (Throwable $e) {
                    $this->transaction->rollback();
                    throw $e;
                }
            }
        });

        $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame(['commit'], $events);
    }

    public function testAClassStringFinalHandlerIsResolvedFromTheContainer(): void
    {
        $definitions = new Definitions();
        $definitions->set(PredefinedResponseHandler::class, new PredefinedResponseHandler(new Response(204, [], 'from container')));
        $runner = new Runner(PredefinedResponseHandler::class, new Container($definitions));

        $response = $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('from container', (string) $response->getBody());
    }

    public function testAClassStringFinalHandlerNeedsAContainer(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A container is required');
        new Runner(PredefinedResponseHandler::class);
    }

    public function testSkippedMiddlewaresCostNoStackFrame(): void
    {
        $depthFor = function (int $count): int {
            $depth = 0;
            $runner = new Runner(function () use (&$depth): ResponseInterface {
                $depth = count(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
                return new Response(200);
            });
            for ($i = 0; $i < $count; $i++) {
                $runner->add(
                    new class implements MiddlewareInterface {
                        public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
                        {
                            return $handler->handle($request);
                        }
                    },
                    when: static fn(): bool => false,
                );
            }
            $runner->handle(new ServerRequest('GET', '/'));
            return $depth;
        };

        // Walking past a false condition is a loop, not a recursion
        $this->assertSame($depthFor(1), $depthFor(50));
    }

    public function testASkippedMiddlewareIsNeverResolved(): void
    {
        $container = new class implements ContainerInterface {
            public bool $used = false;

            public function get(string $id): mixed
            {
                $this->used = true;
                throw new LogicException('must not be resolved');
            }

            public function has(string $id): bool
            {
                return true;
            }
        };

        $runner = new Runner(fn(): ResponseInterface => new Response(200), $container);
        $runner->add('Some\Middleware\That\Does\Not\Exist', when: static fn(): bool => false);

        $this->assertSame(200, $runner->handle(new ServerRequest('GET', '/'))->getStatusCode());
        $this->assertFalse($container->used);
    }

    public function testMultipleRequestsOnSameRunner(): void
    {
        $calls = 0;
        $runner = new Runner(function () use (&$calls): ResponseInterface {
            $calls++;
            return new Response(200, [], (string) $calls);
        });

        $this->assertSame('1', (string) $runner->handle(new ServerRequest('GET', '/'))->getBody());
        $this->assertSame('2', (string) $runner->handle(new ServerRequest('GET', '/'))->getBody());
    }
}
