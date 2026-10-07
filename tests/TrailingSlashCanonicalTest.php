<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\Module;
use Kaly\Http\Exception\RedirectException;
use Kaly\Router\Router;
use Kaly\Router\Routes;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Mocks\RouteHandlerFixture;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use TestModule\Controller\AliasController;

class TrailingSlashCanonicalTest extends TestCase
{
    private function tableModule(): Module
    {
        return (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount('shop')
            ->withoutConventionRouting()
            ->routes(function (Routes $routes): void {
                $routes->get('/foo', RouteHandlerFixture::class)->name('foo');
                $routes->get('/bar/', RouteHandlerFixture::class)->name('bar');
            });
    }

    private function matchDirect(Router $router, string $url): string
    {
        try {
            return $router->match(HttpFactory::createRequestFromGlobals()->withUri(new Uri($url)))->controller;
        } catch (RedirectException $e) {
            $this->fail("Generated url '{$url}' triggered a canonical redirect to '{$e->getUrl()}'");
        }
    }

    public function testAddGeneratesSlashedUrlsThatMatchDirectly(): void
    {
        $router = new Router([$this->tableModule()], null, [], TrailingSlash::Add);

        $this->assertSame('/shop/foo/', $router->url('mapped-module:foo'));
        $this->assertSame('/shop/bar/', $router->url('mapped-module:bar'));
        $this->assertSame(RouteHandlerFixture::class, $this->matchDirect($router, $router->url('mapped-module:foo')));
        $this->assertSame(RouteHandlerFixture::class, $this->matchDirect($router, $router->url('mapped-module:bar')));
    }

    public function testRemoveGeneratesUnslashedUrlsThatMatchDirectly(): void
    {
        $router = new Router([$this->tableModule()], null, [], TrailingSlash::Remove);

        $this->assertSame('/shop/foo', $router->url('mapped-module:foo'));
        $this->assertSame('/shop/bar', $router->url('mapped-module:bar'));
        $this->assertSame(RouteHandlerFixture::class, $this->matchDirect($router, $router->url('mapped-module:foo')));
        $this->assertSame(RouteHandlerFixture::class, $this->matchDirect($router, $router->url('mapped-module:bar')));
    }

    public function testPreserveKeepsTheDeclaredSpellingAndMatchesDirectly(): void
    {
        $router = new Router([$this->tableModule()]);

        $this->assertSame('/shop/foo', $router->url('mapped-module:foo'));
        $this->assertSame('/shop/bar/', $router->url('mapped-module:bar'));
        $this->assertSame(RouteHandlerFixture::class, $this->matchDirect($router, $router->url('mapped-module:foo')));
        $this->assertSame(RouteHandlerFixture::class, $this->matchDirect($router, $router->url('mapped-module:bar')));
    }

    public function testConventionalUrlsMatchDirectly(): void
    {
        require_once __DIR__ . '/modules/TestModule/src/Controller/AliasController.php';

        foreach ([TrailingSlash::Add, TrailingSlash::Remove, TrailingSlash::Preserve] as $policy) {
            $module = (new Module(__DIR__ . '/modules/TestModule'))->mount('test-module');
            $router = new Router([$module], null, [], $policy);
            $url = $router->urlFor([AliasController::class, 'hello']);
            $expected = match ($policy) {
                TrailingSlash::Add => '/test-module/alias/hello/',
                default => '/test-module/alias/hello',
            };
            $this->assertSame($expected, $url);
            $this->assertSame(AliasController::class, $this->matchDirect($router, $url));
        }
    }

    public function testRootNeverRedirects(): void
    {
        require_once __DIR__ . '/modules/TestModule/src/Controller/AliasController.php';

        foreach ([TrailingSlash::Add, TrailingSlash::Remove, TrailingSlash::Preserve] as $policy) {
            $module = (new Module(__DIR__ . '/modules/TestModule'))
                ->mount('/')
                ->routes(static function (Routes $routes): void {
                    $routes->get('/', [AliasController::class, 'hello'])->name('home');
                });
            $router = new Router([$module], null, [], $policy);

            $this->assertSame('/', $router->url('home'));
            $this->assertSame(AliasController::class, $this->matchDirect($router, '/'));
        }
    }
}
