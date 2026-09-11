<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Middleware\GeneratorMiddleware;
use Kaly\Middleware\MiddlewareRunner;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

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
