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

class TrailingSlashTest extends TestCase
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

    private function match(Router $router, string $path): string
    {
        try {
            return $router->match(HttpFactory::createRequestFromGlobals()->withUri(new Uri($path)))->controller;
        } catch (RedirectException $e) {
            return 'redirect:' . $e->getUrl();
        }
    }

    public function testPreserveAcceptsBothSpellingsWithoutRedirect(): void
    {
        $router = new Router([$this->tableModule()]);

        $this->assertSame(RouteHandlerFixture::class, $this->match($router, '/shop/foo'));
        $this->assertSame(RouteHandlerFixture::class, $this->match($router, '/shop/foo/'));
        $this->assertSame(RouteHandlerFixture::class, $this->match($router, '/shop/bar'));
        $this->assertSame(RouteHandlerFixture::class, $this->match($router, '/shop/bar/'));
    }

    public function testPreserveGeneratesTheDeclaredSpelling(): void
    {
        $router = new Router([$this->tableModule()]);

        // /foo declared without slash, /bar/ with one: each keeps its spelling
        $this->assertSame('/shop/foo', $router->url('mapped-module:foo'));
        $this->assertSame('/shop/bar/', $router->url('mapped-module:bar'));

        // Round trip: generated urls match back to their handler
        $this->assertSame(RouteHandlerFixture::class, $this->match($router, $router->url('mapped-module:foo')));
        $this->assertSame(RouteHandlerFixture::class, $this->match($router, $router->url('mapped-module:bar')));
    }

    public function testPreserveGeneratesConventionsWithoutSlashExceptRoot(): void
    {
        require_once __DIR__ . '/modules/TestModule/src/Controller/AliasController.php';
        $module = (new Module(__DIR__ . '/modules/TestModule'))->mount('test-module');
        $router = new Router([$module]);

        $this->assertSame('/test-module/alias/hello', $router->urlFor([AliasController::class, 'hello']));
    }

    public function testAddRedirectsToSlashedAndGeneratesSlashed(): void
    {
        $router = new Router([$this->tableModule()], null, [], TrailingSlash::Add);

        $redirect = $this->match($router, '/shop/foo');
        $this->assertStringStartsWith('redirect:', $redirect);
        $this->assertStringEndsWith('/shop/foo/', $redirect);

        $this->assertSame(RouteHandlerFixture::class, $this->match($router, '/shop/foo/'));
        $this->assertSame('/shop/foo/', $router->url('mapped-module:foo'));
        // No file-like exception: an app on Add canonicalizes dotted routes too
        $this->assertSame('/shop/bar/', $router->url('mapped-module:bar'));
    }

    public function testRemoveRedirectsToUnslashedAndKeepsRoot(): void
    {
        $router = new Router([$this->tableModule()], null, [], TrailingSlash::Remove);

        $redirect = $this->match($router, '/shop/foo/');
        $this->assertStringStartsWith('redirect:', $redirect);
        $this->assertStringEndsWith('/shop/foo', $redirect);

        $this->assertSame(RouteHandlerFixture::class, $this->match($router, '/shop/foo'));
        $this->assertSame('/shop/foo', $router->url('mapped-module:foo'));
        $this->assertSame('/shop/bar', $router->url('mapped-module:bar'));
    }

    public function testRootIsNeverARedirect(): void
    {
        $module = (new Module(__DIR__ . '/modules/TestModule'))
            ->mount('/')
            ->routes(static function (Routes $routes): void {
                $routes->get('/', [AliasController::class, 'hello'])->name('home');
            });
        require_once __DIR__ . '/modules/TestModule/src/Controller/AliasController.php';

        foreach ([TrailingSlash::Add, TrailingSlash::Remove, TrailingSlash::Preserve] as $policy) {
            $router = new Router([$module], null, [], $policy);
            $route = $router->match(HttpFactory::createRequestFromGlobals()->withUri(new Uri('/')));
            $this->assertSame(AliasController::class, $route->controller);
            $this->assertSame('/', $router->url('home'));
        }
    }
}
