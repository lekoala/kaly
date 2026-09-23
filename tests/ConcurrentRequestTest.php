<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Fiber;
use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\HttpContext;
use Kaly\Http\ArraySession;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * WorkerTest proves sequential isolation (A finishes, then B, then C).
 *
 * This proves concurrent isolation: two cycles simultaneously active on one
 * booted App, interleaved with Fibers. Fiber is only the cheapest way to
 * trigger simultaneity; no runtime in particular is under test, just Kaly.
 */
class ConcurrentRequestTest extends TestCase
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

    private function requestA(): ServerRequestInterface
    {
        return HttpFactory::createRequestFromGlobals()
            ->withUri(new Uri('/fr/lang-module/index/getlang/'))
            ->withHeader('X-Probe', 'A')
            ->withCookieParams(['theme' => 'clair']);
    }

    private function requestB(): ServerRequestInterface
    {
        // No locale prefix and no Accept-Language: the route default ('en')
        // applies. The fixture app only allows en/fr, so a distinct second
        // locale means fr vs en here.
        return HttpFactory::createRequestFromGlobals()
            ->withUri(new Uri('/test-module/'))
            ->withHeader('X-Probe', 'B')
            ->withCookieParams(['theme' => 'donker']);
    }

    /**
     * @return array{A: ResponseInterface, B: ResponseInterface}
     */
    private function interleave(ServerRequestInterface $requestA, ServerRequestInterface $requestB): array
    {
        $app = $this->app;
        $responses = [];

        $fiberA = new Fiber(static function () use ($app, $requestA, &$responses): void {
            $responses['A'] = $app->handle($requestA);
        });
        $fiberB = new Fiber(static function () use ($app, $requestB, &$responses): void {
            $responses['B'] = $app->handle($requestB);
        });

        // A runs until the suspend middleware, B runs until it suspends too,
        // then each resumes to completion on its own cycle.
        $fiberA->start();
        $fiberB->start();
        $fiberA->resume();
        $fiberB->resume();

        return $responses;
    }

    public function testTwoInterleavedCyclesStayIsolated(): void
    {
        $suspend = new class implements MiddlewareInterface {
            /**
             * @var array<string,string>
             */
            public array $ctxBefore = [];

            /**
             * @var array<string,string>
             */
            public array $ctxAfter = [];

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $label = $request->getHeaderLine('X-Probe');
                $ctx = HttpContext::from($request);
                $this->ctxBefore[$label] = spl_object_hash($ctx);

                Fiber::suspend();

                // After the other fiber ran its own cycle, our context must be
                // untouched and still attached to our request.
                $resumed = HttpContext::from($request);
                $this->ctxAfter[$label] = spl_object_hash($resumed);
                if ($resumed !== $ctx) {
                    throw new \LogicException('HttpContext changed across Fiber suspension');
                }

                return $handler->handle($request);
            }
        };
        $this->app->middleware()->routed($suspend);

        $conditional = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $response = $handler->handle($request);

                return $response->withHeader('X-Admin', 'yes');
            }
        };
        $this->app->middleware()->routed($conditional, when: static fn(HttpContext $ctx): bool => $ctx->route()->module === 'LangModule');

        $seen = [];
        $this->app->addCallback(App::CB_AFTER_REQUEST, static function (HttpContext $ctx) use (&$seen): void {
            $label = $ctx->request()->getHeaderLine('X-Probe');
            $seen[$label] = [
                'ctx' => $ctx,
                'action' => $ctx->hasRoute() ? $ctx->route()->action : null,
                'module' => $ctx->hasRoute() ? $ctx->route()->module : null,
                'locale' => $ctx->hasLocale() ? $ctx->locale() : null,
                'theme' => $ctx->cookies()->get('theme'),
                'status' => $ctx->hasResponse() ? $ctx->response()->getStatusCode() : null,
                'body' => $ctx->hasResponse() ? (string) $ctx->response()->getBody() : null,
                'middlewares' => $ctx->middlewares(),
                'errors' => count($ctx->callbackErrors()),
            ];
        });

        $responses = $this->interleave($this->requestA(), $this->requestB());

        $this->assertCount(2, $seen);

        // Each cycle kept its own route and locale across the suspension
        $this->assertSame('getlang', $seen['A']['action']);
        $this->assertSame('LangModule', $seen['A']['module']);
        $this->assertSame('fr', $seen['A']['locale']);
        $this->assertSame('fr', $seen['A']['body']);

        $this->assertSame('index', $seen['B']['action']);
        $this->assertSame('TestModule', $seen['B']['module']);
        $this->assertSame('en', $seen['B']['locale']);
        $this->assertSame('hello', $seen['B']['body']);

        // Distinct contexts, cookies, middleware traces, callback errors
        $this->assertNotSame($seen['A']['ctx'], $seen['B']['ctx']);
        $this->assertSame('clair', $seen['A']['theme']);
        $this->assertSame('donker', $seen['B']['theme']);
        $this->assertContains($conditional::class, $seen['A']['middlewares']);
        $this->assertNotContains($conditional::class, $seen['B']['middlewares']);
        $this->assertSame(0, $seen['A']['errors']);
        $this->assertSame(0, $seen['B']['errors']);

        // Distinct responses, one per cycle
        $this->assertSame(200, $seen['A']['status']);
        $this->assertSame(200, $seen['B']['status']);
        $this->assertNotSame($responses['A'], $responses['B']);
        $this->assertSame('fr', (string) $responses['A']->getBody());
        $this->assertSame('hello', (string) $responses['B']->getBody());
        $this->assertSame('yes', $responses['A']->getHeaderLine('X-Admin'));
        $this->assertSame('', $responses['B']->getHeaderLine('X-Admin'));

        // The suspend middleware saw a stable context across the interleave
        $this->assertSame($suspend->ctxBefore['A'], $suspend->ctxAfter['A']);
        $this->assertSame($suspend->ctxBefore['B'], $suspend->ctxAfter['B']);
        $this->assertNotSame($suspend->ctxBefore['A'], $suspend->ctxBefore['B']);
    }

    public function testArraySessionsStayIsolatedAcrossFibers(): void
    {
        $injector = new class implements MiddlewareInterface {
            /**
             * @var array<string,mixed>
             */
            public array $ownerAfterResume = [];

            /**
             * @var array<string,string>
             */
            public array $sessionIds = [];

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $ctx = HttpContext::from($request);
                $ctx->useSession(new ArraySession([], $request));

                $label = $request->getHeaderLine('X-Probe');
                $ctx->session()->set('owner', $label);
                $this->sessionIds[$label] = $ctx->session()->getId() ?? '';

                Fiber::suspend();

                // The other fiber wrote its own owner meanwhile; ours survived.
                $this->ownerAfterResume[$label] = $ctx->session()->get('owner');

                return $handler->handle($request);
            }
        };
        $this->app->middleware()->incoming($injector);

        $responses = $this->interleave($this->requestA(), $this->requestB());

        $this->assertSame('A', $injector->ownerAfterResume['A']);
        $this->assertSame('B', $injector->ownerAfterResume['B']);
        $this->assertNotSame($injector->sessionIds['A'], $injector->sessionIds['B']);

        $this->assertSame('fr', (string) $responses['A']->getBody());
        $this->assertSame('hello', (string) $responses['B']->getBody());
    }

    public function testCookiesStayIsolatedAcrossFibers(): void
    {
        $probe = new class implements MiddlewareInterface {
            /**
             * @var array<string,HttpContext>
             */
            public array $contexts = [];

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $ctx = HttpContext::from($request);
                $label = $request->getHeaderLine('X-Probe');

                // Each cycle stages its own outgoing cookie, then suspends
                // while the other cycle runs.
                $ctx->cookies()->set('request', $label);
                $this->contexts[$label] = $ctx;

                Fiber::suspend();

                return $handler->handle($request);
            }
        };
        $this->app->middleware()->routed($probe);

        $responses = $this->interleave($this->requestA(), $this->requestB());

        // Emission stays explicit and per-cycle: each response carries only
        // its own Set-Cookie, built from its own request-scoped Cookies.
        $withCookies = [];
        foreach (['A' => $responses['A'], 'B' => $responses['B']] as $label => $response) {
            $withCookies[$label] = $probe->contexts[$label]->cookies()->addToResponse($response);
        }

        $cookieA = $withCookies['A']->getHeaderLine('Set-Cookie');
        $cookieB = $withCookies['B']->getHeaderLine('Set-Cookie');

        $this->assertStringContainsString('request=A', $cookieA);
        $this->assertStringNotContainsString('request=B', $cookieA);
        $this->assertStringContainsString('request=B', $cookieB);
        $this->assertStringNotContainsString('request=A', $cookieB);
    }

    public function testClassStringMiddlewareIsASharedInstanceAcrossInterleavedCycles(): void
    {
        $this->app->middleware()->routed(SharedLocalStateMiddleware::class);

        $responses = $this->interleave($this->requestA(), $this->requestB());

        $shared = $this->app->getContainer()->get(SharedLocalStateMiddleware::class);
        assert($shared instanceof SharedLocalStateMiddleware);

        // One application-scoped instance served both cycles
        $this->assertSame($shared->seenIds['A'], $shared->seenIds['B']);
        $this->assertSame(spl_object_id($shared), $shared->seenIds['A']);

        // Correctly written code keeps per-request state in locals: each
        // cycle resumed with its own label despite sharing the instance
        $this->assertSame('A', $shared->localAfterResume['A']);
        $this->assertSame('B', $shared->localAfterResume['B']);

        $this->assertSame('fr', (string) $responses['A']->getBody());
        $this->assertSame('hello', (string) $responses['B']->getBody());
    }
}

/**
 * Named so the test can register it as a class string: it goes through
 * `$container->get()`, exactly like an application middleware.
 */
class SharedLocalStateMiddleware implements MiddlewareInterface
{
    /**
     * @var array<string,int>
     */
    public array $seenIds = [];

    /**
     * @var array<string,string>
     */
    public array $localAfterResume = [];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $label = $request->getHeaderLine('X-Probe');
        $this->seenIds[$label] = spl_object_id($this);

        $local = $label;

        Fiber::suspend();

        $this->localAfterResume[$label] = $local;

        return $handler->handle($request);
    }
}
