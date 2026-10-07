<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\HttpContext;
use Kaly\Core\Middleware\OutgoingInterface;
use Kaly\Di\Definitions;
use Kaly\Http\Session\ArraySession;
use Kaly\Http\Session\ArraySessionProvider;
use Kaly\Http\Session\SessionInterface;
use Kaly\Http\Session\SessionProviderInterface;
use Kaly\Router\TrailingSlash;
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
        $this->app = (new App(__DIR__))->routing(TrailingSlash::Add, true);
        // A request-scoped session keeps this test away from $_SESSION
        $this->app->configure(static function (Definitions $di): void {
            $di->rebind(SessionProviderInterface::class, new ArraySessionProvider());
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

    public function testSessionWrittenInOutgoingIsCommitted(): void
    {
        $provider = new class implements SessionProviderInterface {
            private ArraySessionProvider $inner;
            /**
             * @var array<string,mixed>
             */
            public array $persisted = [];

            public function __construct()
            {
                $this->inner = new ArraySessionProvider();
            }

            public function create(ServerRequestInterface $request): SessionInterface
            {
                return $this->inner->create($request);
            }

            public function commit(
                SessionInterface $session,
                ServerRequestInterface $request,
                ResponseInterface $response,
            ): ResponseInterface {
                $this->persisted = $session->all();
                return $this->inner->commit($session, $request, $response);
            }
        };

        $app = (new App(__DIR__))->routing(TrailingSlash::Add, true);
        $app->configure(static function (Definitions $di) use ($provider): void {
            $di->rebind(SessionProviderInterface::class, $provider);
        });
        $app->boot();

        $app->middleware()->outgoing(new class implements OutgoingInterface {
            public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
            {
                $ctx->session()->set('late', 'yes');
                return $response;
            }
        });

        $response = $app->handle(HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/state/untouched/')));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['late' => 'yes'], $provider->persisted, 'the data written during outgoing reaches commit()');
        $this->assertStringContainsString(
            'KALYSESSID=',
            $response->getHeaderLine('Set-Cookie'),
            'the session written during outgoing is committed after the phase',
        );
    }

    public function testAFailingCommitStillRunsAlwaysOutgoingWithoutReplayingThePhase(): void
    {
        $app = (new App(__DIR__))->routing(TrailingSlash::Add, true);
        $app->configure(static function (Definitions $di): void {
            $di->rebind(SessionProviderInterface::class, new class implements SessionProviderInterface {
                public function create(ServerRequestInterface $request): SessionInterface
                {
                    return new ArraySession();
                }

                public function commit(
                    SessionInterface $session,
                    ServerRequestInterface $request,
                    ResponseInterface $response,
                ): ResponseInterface {
                    throw new \RuntimeException('commit failed');
                }
            });
        });
        $app->boot();

        $runs = 0;
        $app->middleware()->outgoing(new class($runs) implements OutgoingInterface {
            /**
             * @param int $runs
             */
            public function __construct(
                private int &$runs,
            ) {}

            public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
            {
                $this->runs++;
                return $response;
            }
        });
        $app->middleware()->outgoing(static fn(ResponseInterface $response, HttpContext $ctx): ResponseInterface => $response->withHeader(
            'X-Security',
            'yes',
        ), always: true);

        $response = $app->handle(HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/state/login/')));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('yes', $response->getHeaderLine('X-Security'), 'always runs on a failed commit');
        $this->assertSame(1, $runs, 'a failed commit does not replay the outgoing phase');
    }
}
