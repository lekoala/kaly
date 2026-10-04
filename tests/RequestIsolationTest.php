<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Fiber;
use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\HttpContext;
use Kaly\Http\Csrf\Csrf;
use Kaly\Http\Session\ArraySession;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Two interleaved cycles on one shared App keep their own identity,
 * CSRF secret and CSP nonce, from the routed band down to the rendered
 * template and back to the outgoing header.
 */
class RequestIsolationTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = new App(__DIR__);
        $this->app->boot();
    }

    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    public function testInterleavedCyclesKeepTheirOwnIdentity(): void
    {
        $identify = new class implements MiddlewareInterface {
            /**
             * @var array<string,array{auth:bool,tokenValid:bool,nonceStable:bool}>
             */
            public array $seen = [];

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $label = $request->getHeaderLine('X-Probe');
                $ctx = HttpContext::from($request);
                $ctx->useSession(new ArraySession());
                $principal = new \stdClass();
                $principal->label = $label;
                $permission = strtolower($label) . '.read';
                $ctx->auth()->authenticate($principal, [$permission]);
                $csrf = new Csrf();
                $token = $csrf->token($ctx->session());
                $nonce = $ctx->csp()->nonce();

                Fiber::suspend();

                // After the other fiber ran its own cycle, our state must be
                // untouched and still attached to our request.
                $this->seen[$label] = [
                    'auth' => $ctx->auth()->principal() === $principal && $ctx->auth()->allows($permission),
                    'tokenValid' => $csrf->validate($ctx->session(), $token),
                    'nonceStable' => $ctx->csp()->nonce() === $nonce,
                ];

                return $handler->handle($request);
            }
        };
        $this->app->middleware()->routed($identify);
        $this->app
            ->middleware()
            ->outgoing(static function (ResponseInterface $response, HttpContext $ctx): ResponseInterface {
                return $response->withHeader('X-Nonce', $ctx->csp()->nonce());
            });

        $responses = [];
        $fiberA = new Fiber(function () use (&$responses): void {
            $responses['A'] = $this->app->handle($this->request('A'));
        });
        $fiberB = new Fiber(function () use (&$responses): void {
            $responses['B'] = $this->app->handle($this->request('B'));
        });
        $fiberA->start();
        $fiberB->start();
        $fiberA->resume();
        $fiberB->resume();

        $this->assertSame([true, true, true], array_values($identify->seen['A']));
        $this->assertSame([true, true, true], array_values($identify->seen['B']));

        $bodyA = (string) $responses['A']->getBody();
        $bodyB = (string) $responses['B']->getBody();

        $this->assertStringContainsString('user: A', $bodyA);
        $this->assertStringContainsString('user: B', $bodyB);

        $tokenA = $this->field($bodyA, '/name="_csrf" value="([^"]+)"/');
        $tokenB = $this->field($bodyB, '/name="_csrf" value="([^"]+)"/');
        $this->assertNotSame($tokenA, $tokenB);

        $nonceA = $this->field($bodyA, '/nonce="([^"]+)"/');
        $nonceB = $this->field($bodyB, '/nonce="([^"]+)"/');
        $this->assertNotSame($nonceA, $nonceB);

        $this->assertSame($nonceA, $responses['A']->getHeaderLine('X-Nonce'));
        $this->assertSame($nonceB, $responses['B']->getHeaderLine('X-Nonce'));
    }

    private function request(string $label): ServerRequestInterface
    {
        return HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/whoami/'))->withHeader('X-Probe', $label);
    }

    private function field(string $body, string $pattern): string
    {
        if (preg_match($pattern, $body, $matches) !== 1 || !isset($matches[1]) || !is_string($matches[1])) {
            $this->fail('Expected pattern not found in response body');
        }

        return $matches[1];
    }
}
