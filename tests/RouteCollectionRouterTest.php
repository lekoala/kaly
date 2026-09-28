<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Http\MethodNotAllowedException;
use Kaly\Router\AmbiguousRouteException;
use Kaly\Router\ClassRouter;
use Kaly\Router\CompositeRouter;
use Kaly\Router\RouteCollection;
use Kaly\Router\RouteCollectionRouter;
use Kaly\Router\RouterInterface;
use Kaly\Router\Routes;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class RouteCollectionRouterTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    private function app(): App
    {
        $app = new App(__DIR__);
        $app->boot();
        return $app;
    }

    private function request(App $app, string $path, string $method = 'GET'): ResponseInterface
    {
        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri($path))->withMethod($method);
        return $app->handle($request);
    }

    private function match(App $app, string $path, string $method = 'GET'): \Kaly\Router\Route
    {
        $router = $app->get(RouterInterface::class);
        $this->assertInstanceOf(CompositeRouter::class, $router);
        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri($path))->withMethod($method);
        return $router->match($request);
    }

    public function testAttributeAliasStillMatches(): void
    {
        $response = $this->request($this->app(), '/attr/hello/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('attr-hello', (string) $response->getBody());
    }

    public function testExplicitRouteCoercesInt(): void
    {
        $response = $this->request($this->app(), '/attr/item/42/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('item-42', (string) $response->getBody());
    }

    public function testInvalidIntIsNotFound(): void
    {
        $response = $this->request($this->app(), '/attr/item/abc/');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testMethodMismatchIs405WithAllow(): void
    {
        $app = $this->app();
        $response = $this->request($app, '/attr/save/', 'GET');
        $this->assertSame(405, $response->getStatusCode());
        $this->assertStringContainsString('POST', $response->getHeaderLine('Allow'));

        $response = $this->request($app, '/attr/save/', 'POST');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('attr-saved', (string) $response->getBody());
    }

    public function testConventionStillWorksAsFallback(): void
    {
        $response = $this->request($this->app(), '/test-module/index/typed-int/123/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('123', (string) $response->getBody());
    }

    public function testRemovingTheAliasKeepsTheConvention(): void
    {
        // /attr/hello only exists as an explicit alias: no convention route.
        $response = $this->request($this->app(), '/test-module/attribute-demo/hello/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('attr-hello', (string) $response->getBody());
    }

    public function testRepeatableAttributeExposesTheSecondUrl(): void
    {
        $response = $this->request($this->app(), '/legacy/hello/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('attr-hello', (string) $response->getBody());
    }

    public function testExplicit405HasAuthorityOverTheConvention(): void
    {
        require_once __DIR__ . '/modules/TestModule/src/Controller/IndexController.php';

        $routes = new Routes();
        // The conventional route exists for GET; the explicit table claims
        // the path for POST only.
        $routes->post('/test-module/index/foo', [\TestModule\Controller\IndexController::class, 'foo']);
        $composite = new CompositeRouter(
            new RouteCollectionRouter(new RouteCollection($routes->definitions())),
            (new ClassRouter())->mount('test-module', 'TestModule'),
        );

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/foo/'))->withMethod('GET');
        try {
            $composite->match($request);
            $this->fail('Explicit 405 must win over a conventional match');
        } catch (MethodNotAllowedException $e) {
            $this->assertSame(['POST'], $e->getAllowedMethods());
        }
    }

    public function testGenerateByHandlerFailsWhenAmbiguous(): void
    {
        require_once __DIR__ . '/modules/TestModule/src/Controller/AttributeDemoController.php';

        $router = $this->app()->get(RouterInterface::class);
        $this->assertInstanceOf(CompositeRouter::class, $router);
        $this->expectException(AmbiguousRouteException::class);
        $router->generate([\TestModule\Controller\AttributeDemoController::class, 'priority']);
    }

    public function testGenerateUsesDefaults(): void
    {
        $router = $this->app()->get(RouterInterface::class);
        $this->assertInstanceOf(CompositeRouter::class, $router);
        $this->assertSame('/shop/featured/all/', $router->generate('shop.featured'));
        $this->assertSame('/shop/featured/news/', $router->generate('shop.featured', ['tab' => 'news']));
    }

    public function testGenerateWithoutRequiredParamFails(): void
    {
        $router = $this->app()->get(RouterInterface::class);
        $this->assertInstanceOf(CompositeRouter::class, $router);
        $this->expectException(\RuntimeException::class);
        $router->generate('shop.show');
    }

    public function testRoutesPhpIsLoaded(): void
    {
        $response = $this->request($this->app(), '/shop/books/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('shop-books', (string) $response->getBody());
    }

    public function testRoutesPhpRequirementsAreEnforced(): void
    {
        $response = $this->request($this->app(), '/shop/ABC/');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRoutesPhpGroup(): void
    {
        $response = $this->request($this->app(), '/api/health/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', (string) $response->getBody());
    }

    public function testGenerateByName(): void
    {
        $router = $this->app()->get(RouterInterface::class);
        $this->assertInstanceOf(CompositeRouter::class, $router);
        $this->assertSame('/attr/hello/', $router->generate('attr.hello'));
        $this->assertSame('/shop/books/', $router->generate('shop.show', ['slug' => 'books']));
    }

    public function testResolvedRouteKeepsItsDefinition(): void
    {
        $route = $this->match($this->app(), '/shop/books/');
        $this->assertSame('shop.show', $route->definition?->name);
    }

    public function testConventionMatchHasNoDefinition(): void
    {
        $route = $this->match($this->app(), '/test-module/index/foo/');
        $this->assertNull($route->definition);
    }
}
