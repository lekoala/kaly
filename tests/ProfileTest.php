<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Fiber;
use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\HttpContext;
use Kaly\Debug\Profile;
use Kaly\Di\Definitions;
use Kaly\Ex;
use Kaly\Http\Session\ArraySession;
use Kaly\Http\Session\SessionInterface;
use Kaly\Http\Session\SessionProviderInterface;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Support\HttpFactory;
use Kaly\View\RenderEnvironmentInterface;
use Kaly\View\RendererInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

class ProfileTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    public function testDurationsAccumulateInMillisecondsWithCounts(): void
    {
        $profile = new Profile();
        $profile->record('db', 1_500_000);
        $profile->record('db', 2_000_000);
        $this->assertSame(['db' => ['duration' => 3.5, 'count' => 2]], $profile->metrics());
    }

    public function testMetricNamesCannotInjectHeaders(): void
    {
        $this->expectException(Ex::class);
        (new Profile())->record("db\r\nInjected: yes", 1);
    }

    public function testMetricNameLengthIsBoundedWithoutThrowing(): void
    {
        $profile = new Profile();
        $name = str_repeat('a', 64);
        $profile->record($name, 1_000_000);
        $profile->record($name . 'a', 2_000_000);
        $profile->record('db', 3_000_000);
        $this->assertSame(
            [
                $name => ['duration' => 1.0, 'count' => 1],
                'db' => ['duration' => 3.0, 'count' => 1],
            ],
            $profile->metrics(),
        );
    }

    public function testFullProfileStillAggregatesExistingMetrics(): void
    {
        $profile = new Profile();
        for ($i = 0; $i < 64; $i++) {
            $profile->record('metric_' . $i, 1_000_000);
        }
        $profile->record('overflow', 1_000_000);
        $profile->record('metric_0', 2_000_000);
        $this->assertCount(64, $profile->metrics());
        $this->assertArrayNotHasKey('overflow', $profile->metrics());
        $this->assertSame(['duration' => 3.0, 'count' => 2], $profile->metrics()['metric_0']);
    }

    public function testMetricOverflowDoesNotBreakResponseOrExport(): void
    {
        $app = $this->app();
        $app->middleware()->outgoing(static function (ResponseInterface $response, HttpContext $ctx): ResponseInterface {
            $profile = $ctx->profile();
            for ($i = 0; $i < 1000; $i++) {
                $profile?->record('metric_' . $i, 1_000_000);
            }
            $profile?->record(str_repeat('a', 65), 1_000_000);
            $profile?->record('metric_0', 2_000_000);
            return $response;
        });
        $response = $app->handle($this->request('/test-module/index/foo/'));
        $header = $response->getHeaderLine('Server-Timing');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('foo', (string) $response->getBody());
        $this->assertCount(64, explode(', ', $header));
        $this->assertStringContainsString('metric_0;dur=3.0', $header);
        $this->assertStringNotContainsString('metric_999', $header);
        $this->assertStringNotContainsString(str_repeat('a', 65), $header);
    }

    /**
     * @return iterable<string,array{int|float}>
     */
    public static function invalidDurations(): iterable
    {
        yield 'negative integer' => [-1];
        yield 'negative float' => [-0.5];
        yield 'not a number' => [NAN];
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
    }

    #[DataProvider('invalidDurations')]
    public function testInvalidDurationsAreRejected(int|float $nanoseconds): void
    {
        $profile = new Profile();
        try {
            $profile->record('db', $nanoseconds);
            $this->fail('Invalid duration must fail');
        } catch (Ex $ex) {
            $this->assertSame('Profile durations must be finite and non-negative', $ex->getMessage());
        }
        $this->assertSame([], $profile->metrics());
    }

    public function testDisabledProfilingCreatesNoProfileOrHeader(): void
    {
        $app = $this->app(false);
        $app->onTerminate(function (HttpContext $ctx): void {
            $this->assertNull($ctx->profile());
        });
        $response = $app->handle($this->request('/test-module/index/foo/'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($app->bootProfile());
        $this->assertFalse($response->hasHeader('Server-Timing'));
    }

    public function testCollectionDoesNotEnableHttpExport(): void
    {
        $app = App::create(__DIR__)->routing(TrailingSlash::Add, true)->profiling();
        $seen = null;
        $app->onTerminate(static function (HttpContext $ctx) use (&$seen): void {
            $seen = $ctx->profile();
        });
        $response = $app->handle($this->request('/test-module/index/foo/'));
        $this->assertNotNull($seen);
        $this->assertFalse($response->hasHeader('Server-Timing'));
    }

    public function testOutgoingRecoveryKeepsMeasurementsAndTerminateDoesNotRemeasureRequest(): void
    {
        $app = $this->app();
        $app->middleware()->outgoing(static function (): never {
            throw new RuntimeException('Outgoing failed');
        }, priority: -100);
        $seen = null;
        $app->onTerminate(static function (HttpContext $ctx) use (&$seen): void {
            $seen = $ctx->profile()?->metrics();
        });
        $app->onTerminate(function (HttpContext $ctx) use (&$seen): void {
            $this->assertSame($seen, $ctx->profile()?->metrics());
            throw new RuntimeException('Terminate failed');
        });
        $ctxSeen = null;
        $app->onError(static function (\Throwable $ex, HttpContext $ctx) use (&$ctxSeen): void {
            $ctxSeen = $ctx;
        });
        $response = $app->handle($this->request('/test-module/index/foo/'));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('controller;dur=', $response->getHeaderLine('Server-Timing'));
        $this->assertNotNull($seen);
        $this->assertSame(['routing', 'controller', 'commit', 'request'], array_keys($seen));
        $this->assertSame($seen, $ctxSeen?->profile()?->metrics());
    }

    public function testHttpExportPreservesOtherServerTimings(): void
    {
        $app = $this->app();
        $app->middleware()->outgoing(static fn(ResponseInterface $response): ResponseInterface => $response->withAddedHeader(
            'Server-Timing',
            'cache;dur=1.0',
        ), priority: -100);
        $response = $app->handle($this->request('/test-module/index/foo/'));
        $this->assertStringStartsWith('cache;dur=1.0, ', $response->getHeaderLine('Server-Timing'));
        $this->assertStringContainsString('controller;dur=', $response->getHeaderLine('Server-Timing'));
    }

    public function testExportUsesFinalProfileAndCompletesResponseBeforeTerminate(): void
    {
        $app = $this->app();
        $duringOutgoing = null;
        $completed = null;
        $app->middleware()->outgoing(static function (ResponseInterface $response, HttpContext $ctx) use (
            &$duringOutgoing,
        ): ResponseInterface {
            $duringOutgoing = $ctx->profile()?->metrics();
            $ctx->profile()?->record('outgoing', 2_000_000);
            return $response;
        });
        $app->onTerminate(static function (HttpContext $ctx) use (&$completed): void {
            $completed = [$ctx->response(), $ctx->profile()?->metrics()];
        });
        $response = $app->handle($this->request('/test-module/index/foo/'));
        $this->assertNotNull($duringOutgoing);
        $this->assertArrayNotHasKey('commit', $duringOutgoing);
        $this->assertArrayNotHasKey('request', $duringOutgoing);
        $this->assertNotNull($completed);
        $this->assertSame($response, $completed[0]);
        $this->assertNotNull($completed[1]);
        $expected = [];
        foreach ($completed[1] as $name => $metric) {
            $expected[] = sprintf('%s;dur=%.1F', $name, $metric['duration']);
        }
        $this->assertSame(implode(', ', $expected), $response->getHeaderLine('Server-Timing'));
        $this->assertStringContainsString('outgoing;dur=2.0', $response->getHeaderLine('Server-Timing'));
    }

    /**
     * @return iterable<string,array{string,bool,int}>
     */
    public static function serializationCases(): iterable
    {
        yield 'array' => ['json-array', false, 200];
        yield 'JsonResult' => ['json-result', false, 201];
        yield 'failed array' => ['json-array', true, 500];
        yield 'failed JsonResult' => ['json-result', true, 500];
    }

    #[DataProvider('serializationCases')]
    public function testJsonSerializationIsMeasuredEvenWhenItFails(string $action, bool $fail, int $status): void
    {
        $app = $this->app();
        $seen = null;
        $app->onTerminate(static function (HttpContext $ctx) use (&$seen): void {
            $seen = $ctx->profile()?->metrics();
        });
        $request = $this->request('/test-module/index/' . $action . '/');
        if ($fail) {
            $request = $request->withHeader('X-Serialization-Failure', 'yes');
        }
        $response = $app->handle($request);
        $this->assertSame($status, $response->getStatusCode());
        $this->assertNotNull($seen);
        $this->assertSame(['routing', 'controller', 'serialization', 'commit', 'request'], array_keys($seen));
        $this->assertSame(1, $seen['serialization']['count']);
        $this->assertGreaterThanOrEqual(0, $seen['serialization']['duration']);
        $this->assertStringContainsString('serialization;dur=', $response->getHeaderLine('Server-Timing'));
        if (!$fail) {
            $this->assertSame('{"value":"serialized"}', (string) $response->getBody());
            $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
            if ($action === 'json-result') {
                $this->assertSame('yes', $response->getHeaderLine('X-Json'));
            }
        }
    }

    /**
     * @return iterable<string,array{string,int,list<string>}>
     */
    public static function responseCases(): iterable
    {
        yield 'controller response' => ['/test-module/index/foo/', 200, ['routing', 'controller', 'commit', 'request']];
        yield 'rendered view' => ['/test-module/index/view/', 200, ['routing', 'controller', 'view', 'commit', 'request']];
        yield 'controller exception' => ['/test-module/state/crash/', 500, ['routing', 'controller', 'commit', 'request']];
        yield 'routing exception' => ['/no-such-module/', 404, ['routing', 'commit', 'request']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('responseCases')]
    public function testProfilesIncludeOnlyExecutedStages(string $path, int $status, array $expected): void
    {
        $app = $this->app();
        $seen = null;
        $app->onTerminate(static function (HttpContext $ctx) use (&$seen): void {
            $seen = $ctx->profile()?->metrics();
        });
        $response = $app->handle($this->request($path));
        $this->assertSame($status, $response->getStatusCode());
        $this->assertNotNull($seen);
        $this->assertSame($expected, array_keys($seen));
        foreach ($seen as $metric) {
            $this->assertGreaterThanOrEqual(0, $metric['duration']);
            $this->assertSame(1, $metric['count']);
        }
        $header = $response->getHeaderLine('Server-Timing');
        $this->assertStringContainsString('routing;dur=', $header);
        $this->assertStringContainsString('request;dur=', $header);
        $this->assertStringContainsString('commit;dur=', $header);
        $this->assertStringNotContainsString('boot;dur=', $header);
    }

    public function testBootIsSeparateAndNotRepeatedAcrossWorkerRequests(): void
    {
        $app = $this->app();
        $app->boot();
        $boot = $app->bootProfile()?->metrics();
        $this->assertNotNull($boot);
        $this->assertSame(['boot'], array_keys($boot));
        $this->assertSame(1, $boot['boot']['count']);
        $app->handle($this->request('/test-module/index/foo/'));
        $app->handle($this->request('/test-module/index/foo/'));
        $this->assertSame($boot, $app->bootProfile()?->metrics());
    }

    public function testFailedBootStillRecordsItsDuration(): void
    {
        $app = $this->app();
        $app->onBoot(static function (): never {
            throw new RuntimeException('Boot failed');
        });
        try {
            $app->boot();
            $this->fail('Boot must fail');
        } catch (RuntimeException $ex) {
            $this->assertSame('Boot failed', $ex->getMessage());
        }
        $this->assertSame(1, $app->bootProfile()?->metrics()['boot']['count']);
    }

    public function testIncomingShortCircuitHasNoRoutingOrControllerMetrics(): void
    {
        $app = $this->app();
        $app->middleware()->incoming(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response(204);
            }
        });
        $seen = null;
        $app->onTerminate(static function (HttpContext $ctx) use (&$seen): void {
            $seen = $ctx->profile()?->metrics();
        });
        $response = $app->handle($this->request('/unused/'));
        $this->assertSame(204, $response->getStatusCode());
        $this->assertNotNull($seen);
        $this->assertSame(['commit', 'request'], array_keys($seen));
        $this->assertStringContainsString('request;dur=', $response->getHeaderLine('Server-Timing'));
        $this->assertStringContainsString('commit;dur=', $response->getHeaderLine('Server-Timing'));
    }

    public function testRoutingFinishesBeforeDownstreamExecutionAndViewFailureIsRecorded(): void
    {
        $app = $this->app();
        $probe = new class implements MiddlewareInterface {
            public ?Profile $profile = null;

            /**
             * @var array<string,array{duration: float, count: int}>
             */
            public array $atRouting = [];

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $this->profile = HttpContext::from($request)->profile();
                $this->atRouting = $this->profile?->metrics() ?? [];
                return $handler->handle($request);
            }
        };
        $app->middleware()->routed($probe);
        $app->configure(static function (Definitions $di): void {
            $di->rebind(RendererInterface::class, new class implements RendererInterface {
                public function render(string $template, array $data = [], ?RenderEnvironmentInterface $environment = null): string
                {
                    throw new RuntimeException('Render failed');
                }
            });
        });
        $response = $app->handle($this->request('/test-module/index/view/'));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(['routing'], array_keys($probe->atRouting));
        $this->assertSame(['routing', 'controller', 'view', 'commit', 'request'], array_keys($probe->profile?->metrics() ?? []));
        $this->assertStringContainsString('view;dur=', $response->getHeaderLine('Server-Timing'));
    }

    public function testCommitRecoveryExportsTheRecordedFailedCommit(): void
    {
        $app = $this->app();
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
                    throw new RuntimeException('Commit failed');
                }
            });
        });
        $response = $app->handle($this->request('/test-module/state/login/'));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('commit;dur=', $response->getHeaderLine('Server-Timing'));
        $this->assertSame(1, substr_count($response->getHeaderLine('Server-Timing'), 'controller;dur='));
    }

    public function testInterleavedRequestsHaveIndependentProfiles(): void
    {
        $app = $this->app();
        $probe = new class implements MiddlewareInterface {
            /**
             * @var list<Profile>
             */
            public array $profiles = [];

            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                $profile = HttpContext::from($request)->profile();
                if ($profile !== null) {
                    $this->profiles[] = $profile;
                    $profile->record($request->getHeaderLine('X-Probe'), 1_000_000);
                }
                Fiber::suspend();
                return $handler->handle($request);
            }
        };
        $app->middleware()->routed($probe);
        $app->boot();
        $first = new Fiber(fn(): ResponseInterface => $app->handle($this->request('/test-module/index/foo/')->withHeader(
            'X-Probe',
            'first',
        )));
        $second = new Fiber(fn(): ResponseInterface => $app->handle($this->request('/test-module/index/foo/')->withHeader(
            'X-Probe',
            'second',
        )));
        $first->start();
        $second->start();
        $second->resume();
        $first->resume();
        $this->assertNotSame($probe->profiles[0], $probe->profiles[1]);
        $this->assertArrayNotHasKey('second', $probe->profiles[0]->metrics());
        $this->assertArrayNotHasKey('first', $probe->profiles[1]->metrics());
        $this->assertSame(1, $probe->profiles[0]->metrics()['request']['count']);
        $this->assertSame(1, $probe->profiles[1]->metrics()['request']['count']);
    }

    private function app(bool $enabled = true): App
    {
        return App::create(__DIR__)->routing(TrailingSlash::Add, true)->profiling($enabled, serverTiming: true);
    }

    private function request(string $path): ServerRequestInterface
    {
        return HttpFactory::createRequestFromGlobals()->withUri(new Uri($path))->withHeader('Accept', 'text/html');
    }
}
