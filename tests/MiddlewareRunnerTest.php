<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Generator;
use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Middleware\GeneratorMiddleware;
use Kaly\Middleware\GeneratorMiddlewareInterface;
use Kaly\Middleware\MiddlewareRunner;
use Kaly\Middleware\PredefinedResponseHandler;
use LogicException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

class MiddlewareRunnerTest extends TestCase
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
        $runner = new MiddlewareRunner($this->finalHandler());
        $response = $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame('final', (string) $response->getBody());
    }

    public function testPsr15MiddlewareRunsAroundHandler(): void
    {
        $runner = new MiddlewareRunner($this->finalHandler());
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
        $runner = new MiddlewareRunner($this->finalHandler());
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
        $runner = new MiddlewareRunner($this->finalHandler());
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

    public function testGeneratorMiddlewareBeforeAfter(): void
    {
        $runner = new MiddlewareRunner(function (ServerRequestInterface $request): ResponseInterface {
            return new Response(200, [], (string) $request->getAttribute('before'));
        });
        $runner->add(new class extends GeneratorMiddleware {
            public function before(ServerRequestInterface $request): ServerRequestInterface
            {
                return $request->withAttribute('before', 'yes');
            }

            public function after(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
            {
                return $response->withHeader('X-After', 'ran');
            }
        });

        $response = $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame('yes', (string) $response->getBody());
        $this->assertSame('ran', $response->getHeaderLine('X-After'));
    }

    public function testAfterReceivesTheRequestReturnedByItsOwnBefore(): void
    {
        $runner = new MiddlewareRunner(fn(): ResponseInterface => new Response(200));
        $runner->add(new class extends GeneratorMiddleware {
            public function before(ServerRequestInterface $request): ServerRequestInterface|ResponseInterface
            {
                return $request->withAttribute('tag', 'set-in-before');
            }

            public function after(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
            {
                return $response->withHeader('X-Tag', (string) $request->getAttribute('tag'));
            }
        });

        $response = $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame('set-in-before', $response->getHeaderLine('X-Tag'));
    }

    public function testBeforeCanReturnAResponseAndSkipsItsOwnAfter(): void
    {
        $ran = false;
        $runner = new MiddlewareRunner(function () use (&$ran): ResponseInterface {
            $ran = true;
            return new Response(200);
        });
        $runner->add(new class extends GeneratorMiddleware {
            public function before(ServerRequestInterface $request): ServerRequestInterface|ResponseInterface
            {
                return new Response(401, [], 'denied');
            }

            public function after(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
            {
                return $response->withHeader('X-After', 'ran');
            }
        });

        $response = $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('denied', (string) $response->getBody());
        // The middleware owns the response, its own after must not wrap it
        $this->assertFalse($response->hasHeader('X-After'));
        $this->assertFalse($ran, 'the inner layers must not run');
    }

    public function testAnOuterAfterStillWrapsAShortCircuit(): void
    {
        $runner = new MiddlewareRunner(fn(): ResponseInterface => new Response(200));
        $runner->add(new class extends GeneratorMiddleware {
            public function after(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
            {
                return $response->withHeader('X-Outer', 'ran');
            }
        });
        $runner->add(new class extends GeneratorMiddleware {
            public function before(ServerRequestInterface $request): ServerRequestInterface|ResponseInterface
            {
                return new Response(401);
            }
        });

        $response = $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('ran', $response->getHeaderLine('X-Outer'));
    }

    public function testADownstreamExceptionIsThrownAtTheYieldPoint(): void
    {
        $runner = new MiddlewareRunner(function (): ResponseInterface {
            throw new RuntimeException('boom');
        });
        $runner->add(new class implements GeneratorMiddlewareInterface {
            public function process(ServerRequestInterface $request): Generator
            {
                try {
                    return yield $request;
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
        $runner = new MiddlewareRunner(function (): ResponseInterface {
            throw new RuntimeException('boom');
        });
        $runner->add(new class extends GeneratorMiddleware {});

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $runner->handle(new ServerRequest('GET', '/'));
    }

    public function testARecoveredExceptionStillRunsTheOuterAfter(): void
    {
        $runner = new MiddlewareRunner(function (): ResponseInterface {
            throw new RuntimeException('deep');
        });
        $runner->add(new class extends GeneratorMiddleware {
            public function after(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
            {
                return $response->withHeader('X-Outer', 'ran');
            }
        });
        $runner->add(new class implements GeneratorMiddlewareInterface {
            public function process(ServerRequestInterface $request): Generator
            {
                try {
                    return yield $request;
                } catch (Throwable $e) {
                    return new Response(503);
                }
            }
        });

        $response = $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('ran', $response->getHeaderLine('X-Outer'));
    }

    public function testYieldingTwiceIsAProtocolError(): void
    {
        $runner = new MiddlewareRunner(fn(): ResponseInterface => new Response(200));
        $runner->add(new class implements GeneratorMiddlewareInterface {
            public function process(ServerRequestInterface $request): Generator
            {
                yield $request;
                return yield $request;
            }
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must yield at most once');
        $runner->handle(new ServerRequest('GET', '/'));
    }

    public function testNotReturningAResponseIsAProtocolError(): void
    {
        $runner = new MiddlewareRunner(fn(): ResponseInterface => new Response(200));
        $runner->add(new class implements GeneratorMiddlewareInterface {
            public function process(ServerRequestInterface $request): Generator
            {
                yield $request;
                return;
            }
        });

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must return a Psr\Http\Message\ResponseInterface');
        $runner->handle(new ServerRequest('GET', '/'));
    }

    public function testAClassStringFinalHandlerIsResolvedFromTheContainer(): void
    {
        $definitions = new Definitions();
        $definitions->set(PredefinedResponseHandler::class, new PredefinedResponseHandler(new Response(204, [], 'from container')));
        $runner = new MiddlewareRunner(PredefinedResponseHandler::class, new Container($definitions));

        $response = $runner->handle(new ServerRequest('GET', '/'));
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('from container', (string) $response->getBody());
    }

    public function testAClassStringFinalHandlerNeedsAContainer(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A container is required');
        new MiddlewareRunner(PredefinedResponseHandler::class);
    }

    public function testMultipleRequestsOnSameRunner(): void
    {
        $calls = 0;
        $runner = new MiddlewareRunner(function () use (&$calls): ResponseInterface {
            $calls++;
            return new Response(200, [], (string) $calls);
        });

        $this->assertSame('1', (string) $runner->handle(new ServerRequest('GET', '/'))->getBody());
        $this->assertSame('2', (string) $runner->handle(new ServerRequest('GET', '/'))->getBody());
    }
}
