<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\Ex;
use Kaly\Core\HttpContext;
use Kaly\Router\RouteCollection;
use Kaly\Router\RouteDefinition;
use Kaly\Tests\Mocks\DenyMiddleware;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TraceClassMiddleware;
use Kaly\Tests\Mocks\TraceMethodMiddleware;
use Kaly\Tests\Mocks\TraceParentMiddleware;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TestModule\Controller\ShopController;

/**
 * A declared middleware always runs, or the declaration fails loudly.
 */
class RouteMiddlewareTest extends TestCase
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

    private function get(string $path): ResponseInterface
    {
        return $this->app->handle(HttpFactory::createRequestFromGlobals()->withUri(new Uri($path)));
    }

    public function testAGroupMiddlewareGuardsItsRoutes(): void
    {
        $response = $this->get('/locked/health/');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('denied', (string) $response->getBody());
    }

    public function testRouteMiddlewaresRunOutermostFirstAndOnce(): void
    {
        $response = $this->get('/guarded/trace/');

        $this->assertSame(200, $response->getStatusCode());
        // group, then parent class, then class, then method; the parent
        // middleware repeated on the method keeps its outermost place
        $this->assertSame('group,parent,class,method', (string) $response->getBody());
    }

    public function testControllerAttributesApplyToConventionRoutes(): void
    {
        $this->assertSame('parent,class,method', (string) $this->get('/test-module/guarded/')->getBody());
        $this->assertSame('parent,class', (string) $this->get('/test-module/guarded/open/')->getBody());
    }

    public function testRouteMiddlewaresAreTracedOnTheContext(): void
    {
        $ctx = null;
        $this->app->addCallback(App::CB_AFTER_REQUEST, static function (HttpContext $c) use (&$ctx): void {
            $ctx = $c;
        });

        $this->get('/test-module/guarded/');

        $this->assertInstanceOf(HttpContext::class, $ctx);
        $this->assertSame(
            [TraceParentMiddleware::class, TraceClassMiddleware::class, TraceMethodMiddleware::class],
            $ctx->route()->middlewares,
        );
        $this->assertTrue($ctx->hasMiddleware(TraceMethodMiddleware::class));
    }

    public function testAnUnknownMiddlewareFailsAtBoot(): void
    {
        $this->expectException(Ex::class);
        $this->expectExceptionMessage("Middleware 'Nope\\Missing' declared on 'route '/x'' does not exist");

        new RouteCollection([new RouteDefinition('/x', ShopController::class, 'health', middlewares: ['Nope\\Missing'])]);
    }

    public function testANonMiddlewareClassFailsAtBoot(): void
    {
        $this->expectException(Ex::class);
        $this->expectExceptionMessage('is not a request middleware');

        new RouteCollection([new RouteDefinition('/x', ShopController::class, 'health', middlewares: [TestObject::class])]);
    }

    public function testAMiddlewareDeclaredOnRouteAndGroupRunsOnce(): void
    {
        $collection = new RouteCollection([
            new RouteDefinition('/x', ShopController::class, 'health', middlewares: [DenyMiddleware::class, DenyMiddleware::class]),
        ]);

        $this->assertSame([DenyMiddleware::class], $collection->toArray()[0]['middlewares']);
    }
}
