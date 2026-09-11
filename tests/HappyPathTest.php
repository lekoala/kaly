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
 * One walk through everything a real application relies on:
 *
 * ```text
 * boot -> incoming middleware -> routing -> routed middleware
 *      -> controller (services by constructor, int from the url, typed input)
 *      -> View / JSON -> response
 * ```
 *
 * and the four failure modes that must stay distinguishable.
 */
class HappyPathTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = new App(__DIR__);
        $this->app->boot();

        $this->app
            ->middleware()
            ->incoming(new class extends GeneratorMiddleware {
                public function before(ServerRequestInterface $request): ServerRequestInterface|ResponseInterface
                {
                    return $request->withAttribute('request-id', 'rid-1');
                }

                public function after(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
                {
                    return $response->withHeader('X-Request-Id', (string) $request->getAttribute('request-id'));
                }
            })
            ->routed(
                new class extends GeneratorMiddleware {
                    public function after(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
                    {
                        return $response->withHeader('X-Module', 'yes');
                    }
                },
                when: static fn(HttpContext $ctx): bool => $ctx->route()->module === 'TestModule',
            );
    }

    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    /**
     * @param array<string,mixed> $query
     */
    private function get(string $path, array $query = []): ResponseInterface
    {
        return $this->app->handle(HttpFactory::createRequestFromGlobals()->withUri(new Uri($path))->withQueryParams($query));
    }

    public function testAnHtmlPageGoesThroughTheWholeStack(): void
    {
        $response = $this->get('/test-module/index/view/');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('View test', (string) $response->getBody());

        // Both bands ran, in both phases
        $this->assertSame('rid-1', $response->getHeaderLine('X-Request-Id'));
        $this->assertSame('yes', $response->getHeaderLine('X-Module'));
    }

    public function testAJsonEndpointWithASegmentAndATypedInput(): void
    {
        // /edit/12/ -> int $id, ?q= -> SearchInput
        $response = $this->get('/test-module/input/edit/12/', ['q' => 'hello']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('12:hello', (string) $response->getBody());
        $this->assertSame('rid-1', $response->getHeaderLine('X-Request-Id'));
    }

    public function testTheRoutedBandIsSkippedForAnotherModule(): void
    {
        $response = $this->get('/fr/lang-module/index/getlang/');

        $this->assertSame(200, $response->getStatusCode());
        // Incoming always runs, routed only matched TestModule
        $this->assertSame('rid-1', $response->getHeaderLine('X-Request-Id'));
        $this->assertSame('', $response->getHeaderLine('X-Module'));
    }

    public function testTheFourFailureModesStayDistinct(): void
    {
        $cases = [
            // no such route at all
            404 => ['/test-module/index/typed-int/abc/', []],
            // the route exists, the input cannot be built
            400 => ['/test-module/input/search/', ['page' => 'abc']],
            // the input is well typed and refused
            422 => ['/test-module/index/validation/', []],
            // a programming error
            500 => ['/test-module/input/with-service/', []],
        ];

        foreach ($cases as $expected => [$path, $query]) {
            $this->assertSame($expected, $this->get($path, $query)->getStatusCode(), $path);
        }
    }

    public function testAnErrorResponseDoesNotGoThroughTheAfterPhases(): void
    {
        // An exception unwinds past the after phases, so a response the kernel
        // builds from it carries none of their headers. This is deliberate: a
        // failed stack entered some middlewares and not others. A header that
        // must be on every response, error ones included, needs a response
        // finalization step, which does not exist yet.
        $ok = $this->get('/test-module/');
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame('rid-1', $ok->getHeaderLine('X-Request-Id'));

        $notFound = $this->get('/test-module/nope/');
        $this->assertSame(404, $notFound->getStatusCode());
        $this->assertSame('', $notFound->getHeaderLine('X-Request-Id'));
    }
}
