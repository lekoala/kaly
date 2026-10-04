<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\HttpContext;
use Kaly\Core\Middleware\CsrfMiddleware;
use Kaly\Http\Csrf\Csrf;
use Kaly\Http\Exception\InvalidCsrfTokenException;
use Kaly\Http\Exception\InvalidMethodOverrideException;
use Kaly\Http\MethodOverrideMiddleware;
use Kaly\Http\Session\ArraySession;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Only real POST requests tunnel to PUT, PATCH or DELETE, before routing,
 * so downstream guards (notably CSRF) see the effective method.
 */
class MethodOverrideTest extends TestCase
{
    private MethodOverrideMiddleware $middleware;

    /** @var list<string> */
    private array $seen = [];

    protected function setUp(): void
    {
        $this->middleware = new MethodOverrideMiddleware();
        $this->seen = [];
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,string> $headers
     */
    private function request(string $method, array $body = [], array $headers = []): ServerRequestInterface
    {
        $request = new ServerRequest($method, '/item/42');

        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        return $request;
    }

    private function handler(): RequestHandlerInterface
    {
        return new class($this) implements RequestHandlerInterface {
            public function __construct(
                private MethodOverrideTest $test,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->test->record($request->getMethod());

                return new Response(200, [], $request->getMethod());
            }
        };
    }

    public function record(string $method): void
    {
        $this->seen[] = $method;
    }

    /**
     * @return array<string,string>
     */
    private function form(): array
    {
        return ['Content-Type' => 'application/x-www-form-urlencoded'];
    }

    public function testNonPostRequestsAreUntouched(): void
    {
        $this->middleware->process($this->request('GET', ['_method' => 'DELETE'], $this->form()), $this->handler());

        $this->assertSame(['GET'], $this->seen);
    }

    public function testPostWithoutOverrideStaysPost(): void
    {
        $this->middleware->process($this->request('POST', [], $this->form()), $this->handler());

        $this->assertSame(['POST'], $this->seen);
    }

    public function testFormFieldOverrides(): void
    {
        foreach (['PUT', 'PATCH', 'DELETE'] as $target) {
            $this->middleware->process($this->request('POST', ['_method' => $target], $this->form()), $this->handler());
        }

        $this->assertSame(['PUT', 'PATCH', 'DELETE'], $this->seen);
    }

    public function testFieldIsTrimmedAndUpperCased(): void
    {
        $this->middleware->process($this->request('POST', ['_method' => '  delete '], $this->form()), $this->handler());

        $this->assertSame(['DELETE'], $this->seen);
    }

    public function testHeaderOverridesWithoutFormContentType(): void
    {
        $this->middleware->process($this->request('POST', [], ['X-HTTP-Method-Override' => 'PATCH']), $this->handler());

        $this->assertSame(['PATCH'], $this->seen);
    }

    public function testMatchingSourcesAreAccepted(): void
    {
        $this->middleware->process(
            $this->request('POST', ['_method' => 'DELETE'], $this->form() + ['X-HTTP-Method-Override' => 'DELETE']),
            $this->handler(),
        );

        $this->assertSame(['DELETE'], $this->seen);
    }

    public function testConflictingSourcesAreRejected(): void
    {
        $this->expectException(InvalidMethodOverrideException::class);
        $this->middleware->process(
            $this->request('POST', ['_method' => 'DELETE'], $this->form() + ['X-HTTP-Method-Override' => 'PUT']),
            $this->handler(),
        );
    }

    public function testTargetsOutsideTheWhitelistAreRejected(): void
    {
        foreach (['FLY', 'GET', 'POST', 'OPTIONS'] as $target) {
            try {
                $this->middleware->process($this->request('POST', ['_method' => $target], $this->form()), $this->handler());
                $this->fail("Target '{$target}' should have been rejected");
            } catch (InvalidMethodOverrideException $e) {
                $this->assertSame(400, $e->status());
            }
        }

        $this->assertSame([], $this->seen);
    }

    public function testEmptyFieldIsNoOverride(): void
    {
        $this->middleware->process($this->request('POST', ['_method' => ''], $this->form()), $this->handler());

        $this->assertSame(['POST'], $this->seen);
    }

    public function testJsonBodiesNeverOverride(): void
    {
        $this->middleware->process(
            $this->request('POST', ['_method' => 'DELETE'], ['Content-Type' => 'application/json']),
            $this->handler(),
        );

        $this->assertSame(['POST'], $this->seen);
    }

    public function testCsrfSeesTheOverriddenMethod(): void
    {
        $csrf = new Csrf();
        $session = new ArraySession();
        $token = $csrf->token($session);
        $csrfMiddleware = new CsrfMiddleware($csrf);
        $downstream = new class($csrfMiddleware) implements RequestHandlerInterface {
            public function __construct(
                private MiddlewareInterface $middleware,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->middleware->process($request, new class implements RequestHandlerInterface {
                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        return new Response(200, [], $request->getMethod());
                    }
                });
            }
        };

        $withSession = static function (ServerRequestInterface $request) use ($session): ServerRequestInterface {
            $ctx = new HttpContext($request);
            $ctx->useSession($session);

            return $request->withAttribute(HttpContext::ATTRIBUTE, $ctx);
        };

        try {
            $this->middleware->process($withSession($this->request('POST', ['_method' => 'DELETE'], $this->form())), $downstream);
            $this->fail('Missing CSRF token should have been rejected');
        } catch (InvalidCsrfTokenException $e) {
            $this->assertSame(403, $e->status());
        }

        $response = $this->middleware->process(
            $withSession($this->request('POST', ['_method' => 'DELETE', '_csrf' => $token], $this->form())),
            $downstream,
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('DELETE', (string) $response->getBody());
    }
}
