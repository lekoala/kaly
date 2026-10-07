<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Router\Route;
use Kaly\Router\RouteGenerationException;
use Kaly\Router\RouterInterface;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TestModule\Controller\AliasController;
use TestModule\Controller\DemoController;
use TestModule\Controller\WhoamiController;

/**
 * The local route table and the claims of a module, declared in its config.php
 * (see tests/modules/TestModule/config.php)
 */
class ModuleRoutesTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = App::create(__DIR__)->routing(TrailingSlash::Add, true)->boot();
    }

    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    private function request(string $path, string $method = 'GET'): ResponseInterface
    {
        return $this->app->handle(HttpFactory::createRequestFromGlobals()->withUri(new Uri($path))->withMethod($method));
    }

    private function match(string $path): Route
    {
        return $this->router()->match(HttpFactory::createRequestFromGlobals()->withUri(new Uri($path)));
    }

    private function router(): RouterInterface
    {
        return $this->app->container()->get(RouterInterface::class);
    }

    public function testALocalRouteIsRelativeToTheModuleMount(): void
    {
        $response = $this->request('/test-module/alias/hello/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('alias-hello', (string) $response->getBody());

        // Nothing leaks outside of the module segment
        $this->assertSame(404, $this->request('/alias/hello/')->getStatusCode());
    }

    public function testATableRouteCoercesInt(): void
    {
        $this->assertSame('item-42', (string) $this->request('/test-module/alias/item/42/')->getBody());
        $this->assertSame(404, $this->request('/test-module/alias/item/abc/')->getStatusCode());
    }

    public function testATableMethodMismatchIsAnAuthoritative405(): void
    {
        // The convention would otherwise look for an AliasController::save
        $response = $this->request('/test-module/alias/save/', 'GET');
        $this->assertSame(405, $response->getStatusCode());
        $this->assertStringContainsString('POST', $response->getHeaderLine('Allow'));

        $response = $this->request('/test-module/alias/save/', 'POST');
        $this->assertSame('alias-saved', (string) $response->getBody());
    }

    public function testTheTableComesBeforeTheConvention(): void
    {
        // Both urls are table routes; the convention no longer exposes
        // AliasController::hello at all since the table owns it
        $this->assertSame('alias-hello', (string) $this->request('/test-module/legacy/hello/')->getBody());
        $this->assertSame('alias-hello', (string) $this->request('/test-module/alias/hello/')->getBody());
        // And the convention still answers what the table does not declare
        $this->assertSame('123', (string) $this->request('/test-module/index/typed-int/123/')->getBody());
    }

    public function testAClaimOwnsAPathOutsideOfTheModuleSegment(): void
    {
        $response = $this->request('/shop/books/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('shop-books', (string) $response->getBody());
        $this->assertSame('ok', (string) $this->request('/api/health/')->getBody());

        // Requirements still apply
        $this->assertSame(404, $this->request('/shop/ABC/')->getStatusCode());

        $route = $this->match('/shop/books/');
        $this->assertSame('TestModule', $route->module);
        $this->assertSame('test-module:shop.show', $route->name);
    }

    public function testAConventionMatchHasNoName(): void
    {
        $route = $this->match('/test-module/index/foo/');
        $this->assertNull($route->name);
    }

    public function testUrlsAreGeneratedByQualifiedName(): void
    {
        $router = $this->router();
        $this->assertSame('/test-module/alias/hello/', $router->url('test-module:alias.hello'));
        $this->assertSame('/test-module/alias/item/7/', $router->url('test-module:alias.item', ['id' => 7]));
        $this->assertSame('/shop/books/', $router->url('test-module:shop.show', ['slug' => 'books']));
        $this->assertSame('/shop/books/?ref=home', $router->url('test-module:shop.show', ['slug' => 'books', 'ref' => 'home']));
    }

    public function testGenerationUsesDefaults(): void
    {
        $this->assertSame('/shop/featured/all/', $this->router()->url('test-module:shop.featured'));
        $this->assertSame('/shop/featured/news/', $this->router()->url('test-module:shop.featured', ['tab' => 'news']));
    }

    public function testGenerationWithoutARequiredParamFails(): void
    {
        $this->expectException(RouteGenerationException::class);
        $this->router()->url('test-module:shop.show');
    }

    public function testAnUnqualifiedNameNeedsADefaultModule(): void
    {
        // The test application has no default (App) module
        $this->expectException(RouteGenerationException::class);
        $this->expectExceptionMessage("must be qualified: 'module:name'");
        $this->router()->url('alias.hello');
    }

    public function testAnExplicitlyRoutedActionHasNoConventionalUrl(): void
    {
        // ShopController::health is declared in the table and in claims: its
        // conventional urls stay closed, whatever the HTTP method
        $this->assertSame(404, $this->request('/test-module/shop/health/')->getStatusCode());
        $this->assertSame(404, $this->request('/test-module/shop/health/', 'POST')->getStatusCode());
        // Claim-owned actions are owned too
        $this->assertSame(404, $this->request('/test-module/shop/show/books/')->getStatusCode());
    }

    public function testUrlForAnExplicitlyRoutedActionSuggestsItsRouteName(): void
    {
        try {
            $this->router()->urlFor([WhoamiController::class, 'index']);
            $this->fail('urlFor() must fail for an explicitly routed action');
        } catch (RouteGenerationException $e) {
            $this->assertSame(
                "'TestModule\\Controller\\WhoamiController::index' is explicitly routed and has no conventional url: use route 'test-module:whoami'",
                $e->getMessage(),
            );
        }
    }

    public function testUrlForAnActionWithSeveralExplicitRoutesNamesNoneOfThem(): void
    {
        try {
            $this->router()->urlFor([AliasController::class, 'hello']);
            $this->fail('urlFor() must fail for an explicitly routed action');
        } catch (RouteGenerationException $e) {
            $this->assertSame(
                "'TestModule\\Controller\\AliasController::hello' is explicitly routed by multiple routes; generate one by name",
                $e->getMessage(),
            );
        }
    }

    public function testConventionalUrlsIgnoreNothing(): void
    {
        // The convention still generates what the tables do not own
        $this->assertSame('/test-module/demo/method/', $this->router()->urlFor([DemoController::class, 'methodGet']));
    }
}
