<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\HttpContext;
use Kaly\Core\Middleware\OutgoingInterface;
use Kaly\Di\Definitions;
use Kaly\Http\Session\ArraySessionProvider;
use Kaly\Http\Session\SessionProviderInterface;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

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
        // A request-scoped session keeps this test away from $_SESSION
        $this->app->configure(static function (Definitions $di): void {
            $di->set(SessionProviderInterface::class, new ArraySessionProvider());
        });
        $this->app->boot();
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

    /**
     * @return OutgoingInterface
     */
    private function failingOutgoing(): OutgoingInterface
    {
        return new class implements OutgoingInterface {
            public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
            {
                throw new \RuntimeException('outgoing failed');
            }
        };
    }

    public function testCookiesSurviveAFailingOutgoing(): void
    {
        $this->app->middleware()->outgoing($this->failingOutgoing());

        $response = $this->get('/test-module/state/cookie/');

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString(
            'theme=dark',
            $response->getHeaderLine('Set-Cookie'),
            'a cookie set before an outgoing failure must reach the error response',
        );
    }

    public function testSessionAndCookiesSurviveAFailingOutgoing(): void
    {
        $this->app->middleware()->outgoing($this->failingOutgoing());

        $response = $this->get('/test-module/state/login/');

        $this->assertSame(500, $response->getStatusCode());
        $cookies = implode("\n", $response->getHeader('Set-Cookie'));
        $this->assertStringContainsString('remember=yes', $cookies);
        $this->assertStringContainsString('KALYSESSID=', $cookies);
    }
}
