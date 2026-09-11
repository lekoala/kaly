<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\HttpContext;
use Kaly\Middleware\GeneratorMiddleware;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The kernel claims to be stateless and worker safe. This makes that promise
 * executable: many unrelated requests go through one booted App and nothing of
 * one cycle may leak into the next.
 */
class WorkerTest extends TestCase
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

    /**
     * @param array<string,mixed> $query
     */
    private function get(string $path, array $query = [], ?string $acceptLanguage = null): ResponseInterface
    {
        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri($path))->withQueryParams($query);
        if ($acceptLanguage !== null) {
            $request = $request->withHeader('Accept-Language', $acceptLanguage);
        }
        return $this->app->handle($request);
    }

    public function testManyRequestsOnOneBootedApp(): void
    {
        // Three unrelated surfaces, same container, same kernel
        $this->assertSame('hello', (string) $this->get('/test-module/')->getBody());
        $this->assertSame('[]', (string) $this->get('/test-module/json/')->getBody());
        $this->assertSame('fr', (string) $this->get('/fr/lang-module/index/getlang/')->getBody());
        $this->assertSame('hello', (string) $this->get('/test-module/')->getBody());
    }

    public function testNoContextLeaksBetweenRequests(): void
    {
        $seen = [];
        $this->app->addCallback(App::CB_AFTER_REQUEST, function (HttpContext $ctx) use (&$seen): void {
            $seen[] = [
                'action' => $ctx->hasRoute() ? $ctx->route()->action : null,
                'module' => $ctx->hasRoute() ? $ctx->route()->module : null,
                'locale' => $ctx->hasLocale() ? $ctx->locale() : null,
                'status' => $ctx->hasResponse() ? $ctx->response()->getStatusCode() : null,
                'middlewares' => $ctx->middlewares(),
                'errors' => count($ctx->callbackErrors()),
            ];
        });

        $this->get('/fr/lang-module/index/getlang/');
        $this->get('/test-module/index/typed-int/7/');
        $this->get('/test-module/does-not-exist/');

        $this->assertCount(3, $seen);

        // Each cycle carries its own route, and nothing of the previous one
        $this->assertSame('getlang', $seen[0]['action']);
        $this->assertSame('LangModule', $seen[0]['module']);
        $this->assertSame('fr', $seen[0]['locale']);

        $this->assertSame('typedInt', $seen[1]['action']);
        $this->assertSame('TestModule', $seen[1]['module']);
        // The french locale of the previous request must not stick
        $this->assertSame('en', $seen[1]['locale']);

        // A failed cycle still gets a response, and no route at all
        $this->assertNull($seen[2]['action']);
        $this->assertSame(404, $seen[2]['status']);

        foreach ($seen as $cycle) {
            $this->assertSame(0, $cycle['errors']);
        }
    }

    public function testMiddlewaresAreTrackedPerRequest(): void
    {
        $this->app->middleware()->routed(
            new class extends GeneratorMiddleware {
                public function after(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
                {
                    return $response->withHeader('X-Admin', 'yes');
                }
            },
            // Only for one of the modules
            when: static fn(HttpContext $ctx): bool => $ctx->route()->module === 'LangModule',
        );

        $marked = [];
        $this->app->addCallback(App::CB_AFTER_REQUEST, function (HttpContext $ctx) use (&$marked): void {
            $marked[] = $ctx->middlewares();
        });

        $withIt = $this->get('/fr/lang-module/index/getlang/');
        $withoutIt = $this->get('/test-module/');

        $this->assertSame('yes', $withIt->getHeaderLine('X-Admin'));
        $this->assertSame('', $withoutIt->getHeaderLine('X-Admin'));

        // The conditional middleware entered once and only once
        $this->assertCount(1, $marked[0]);
        $this->assertCount(0, $marked[1]);
    }

    public function testAFailedRequestDoesNotPoisonTheNextOne(): void
    {
        // 500, then 404, then 400, then a healthy request on the same app
        $this->assertSame(500, $this->get('/test-module/input/with-service/')->getStatusCode());
        $this->assertSame(404, $this->get('/test-module/index/typed-int/abc/')->getStatusCode());
        $this->assertSame(400, $this->get('/test-module/input/search/', ['page' => 'abc'])->getStatusCode());
        $this->assertSame(422, $this->get('/test-module/index/validation/')->getStatusCode());

        $healthy = $this->get('/test-module/');
        $this->assertSame(200, $healthy->getStatusCode());
        $this->assertSame('hello', (string) $healthy->getBody());
    }

    public function testTheSameControllerIsRebuiltForEachRequest(): void
    {
        // Controllers are per request, so a request attribute never survives
        $first = $this->get('/test-module/input/search/', ['q' => 'one']);
        $second = $this->get('/test-module/input/search/', ['q' => 'two']);

        $this->assertSame('one:1', (string) $first->getBody());
        $this->assertSame('two:1', (string) $second->getBody());
    }
}
