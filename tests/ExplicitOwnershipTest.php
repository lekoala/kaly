<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\Module;
use Kaly\Router\RouteGenerationException;
use Kaly\Router\RouteNotFoundException;
use Kaly\Router\Router;
use Kaly\Router\RouterInterface;
use Kaly\Router\Routes;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TestModule\Controller\MixedController;
use TestModule\Controller\ShopController;

/**
 * Explicit routing takes ownership of an action: the convention neither
 * resolves nor generates a url for it, without reinterpreting the path
 * through a sibling action or the index fallback.
 */
class ExplicitOwnershipTest extends TestCase
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

    public function testANonOwnedSiblingStaysConventional(): void
    {
        // buyPost is explicitly routed, buy is not: each follows its own rule
        $this->assertSame('mixed-buy', (string) $this->request('/test-module/mixed/buy/')->getBody());
    }

    public function testAnOwnedActionIsRefusedWithoutFallback(): void
    {
        // Without the early refusal, the variadic index would answer
        // 'mixed-index:buy' here, and bare buy for a POST below
        $this->assertSame(404, $this->request('/test-module/mixed/buy/', 'POST')->getStatusCode());
        $this->assertSame(404, $this->request('/test-module/mixed/buy-post/')->getStatusCode());
        $this->assertSame(404, $this->request('/test-module/mixed/buy-post/', 'POST')->getStatusCode());
    }

    public function testOwnershipIgnoresLetterCase(): void
    {
        $module = (new Module(__DIR__ . '/modules/TestModule'))
            ->mount('case-test')
            ->routes(static function (Routes $routes): void {
                $routes->get('/vitals', [ShopController::class, 'HEALTH'])->name('vitals');
            });
        $router = new Router([$module], null, [], TrailingSlash::Add);

        // The table route itself answers
        $route = $router->match(HttpFactory::createRequestFromGlobals()->withUri(new Uri('/case-test/vitals/')));
        $this->assertSame(ShopController::class, $route->controller);

        // The conventional url stays closed whatever the case
        $this->expectException(RouteNotFoundException::class);
        $router->match(HttpFactory::createRequestFromGlobals()->withUri(new Uri('/case-test/shop/health/')));
    }

    public function testUrlForIgnoresLetterCase(): void
    {
        $module = (new Module(__DIR__ . '/modules/TestModule'))
            ->mount('case-test')
            ->routes(static function (Routes $routes): void {
                $routes->get('/vitals', [ShopController::class, 'HEALTH'])->name('vitals');
            });
        $router = new Router([$module], null, [], TrailingSlash::Add);

        foreach (['HEALTH', 'health'] as $spelling) {
            try {
                $router->urlFor([ShopController::class, $spelling]);
                $this->fail('urlFor() must fail for an explicitly routed action');
            } catch (RouteGenerationException $e) {
                $this->assertSame(
                    "'TestModule\\Controller\\ShopController::{$spelling}' is explicitly routed and has no conventional url: use route 'test-module:vitals'",
                    $e->getMessage(),
                );
            }
        }
    }

    public function testMixedControllerIsExposedAsExpected(): void
    {
        $router = $this->app->container()->get(RouterInterface::class);
        $this->assertInstanceOf(RouterInterface::class, $router);
        // buy is conventional, buyPost is owned by a single named route
        $this->assertSame('/test-module/mixed/buy/', $router->urlFor([MixedController::class, 'buy']));
        $this->assertSame('/test-module/purchase/', $router->url('test-module:mixed.purchase'));
    }
}
