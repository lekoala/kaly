<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\HttpContext;
use Kaly\Http\ArraySession;
use Kaly\Middleware\GeneratorMiddleware;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What a cycle writes to its context reaches the response without any
 * wiring: the kernel commits the session and the cookies.
 */
class CommitTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = new App(__DIR__);
        $this->app->boot();
        // A request-scoped session keeps this test away from $_SESSION
        $this->app
            ->middleware()
            ->incoming(new class extends GeneratorMiddleware {
                public function before(ServerRequestInterface $request): ServerRequestInterface|ResponseInterface
                {
                    HttpContext::from($request)->useSession(new ArraySession([], $request));
                    return $request;
                }
            });
    }

    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    private function get(string $path): ResponseInterface
    {
        return $this->app->handle(HttpFactory::createRequestFromGlobals()->withUri(new Uri($path)));
    }

    public function testCookiesSetByAnActionAreEmitted(): void
    {
        $response = $this->get('/test-module/state/cookie/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('theme=dark', $response->getHeaderLine('Set-Cookie'));
    }

    public function testSessionAndCookiesSurviveARedirect(): void
    {
        $response = $this->get('/test-module/state/login/');

        $this->assertSame(303, $response->getStatusCode());
        $cookies = implode("\n", $response->getHeader('Set-Cookie'));
        $this->assertStringContainsString('remember=yes', $cookies);
        $this->assertStringContainsString('KALYSESSID=', $cookies);
    }

    public function testAnUntouchedCycleEmitsNothing(): void
    {
        $response = $this->get('/test-module/state/untouched/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->hasHeader('Set-Cookie'));
    }
}
