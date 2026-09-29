<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\Module;
use Kaly\Http\MethodNotAllowedException;
use Kaly\Http\RedirectException;
use Kaly\Router\RedirectUris;
use Kaly\Router\Router;
use Kaly\Router\Routes;
use Kaly\Tests\Mocks\RouteHandlerFixture;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\ServerRequest as BaseServerRequest;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Regression tests for the hierarchical routing fixes: 405 aggregation across
 * tables, single-occurrence canonical redirects, explicit failure when
 * generating for a missing locale, duplicate names across tables, and locale
 * canonicalization.
 */
class RouterHierarchyFixesTest extends TestCase
{
    private function module(): Module
    {
        return (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount('shop')
            ->withoutConventionRouting();
    }

    private function match(Router $router, string $path, string $method = 'GET'): mixed
    {
        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri($path))->withMethod($method);
        return $router->match($request);
    }

    public function testSecondTableServesMethodFirstTableRejects(): void
    {
        $module = $this
            ->module()
            ->routes(function (Routes $routes): void {
                $routes->get('/same', RouteHandlerFixture::class);
            })
            ->routes(function (Routes $routes): void {
                $routes->post('/same', RouteHandlerFixture::class);
            });
        $router = new Router([$module]);

        $this->assertNotNull($this->match($router, '/shop/same/', 'GET'));
        $this->assertNotNull($this->match($router, '/shop/same/', 'POST'));

        try {
            $this->match($router, '/shop/same/', 'PUT');
            $this->fail('A 405 was expected');
        } catch (MethodNotAllowedException $e) {
            $this->assertEqualsCanonicalizing(['GET', 'POST'], $e->getAllowedMethods());
        }
    }

    public function testReplaceSegmentOnlyTouchesTheFirstOccurrence(): void
    {
        $uri = RedirectUris::replaceSegment(new BaseServerRequest('GET', 'https://example.test/fr/shop/fr/'), 'fr', '', true);
        $this->assertSame('/shop/fr/', $uri->getPath());

        $uri = RedirectUris::replaceSegment(new BaseServerRequest('GET', 'https://example.test/Shop/product/Shop/'), 'Shop', 'shop', true);
        $this->assertSame('/shop/product/Shop/', $uri->getPath());
    }

    public function testMissingLocaleVariantFailsAtGeneration(): void
    {
        $module = (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount(['fr' => 'boutique', 'en' => 'shop'])
            ->localized()
            ->withoutConventionRouting()
            ->routes(function (Routes $routes): void {
                $routes->get(['fr' => '/a-propos'], RouteHandlerFixture::class)->name('about');
            });
        $router = new Router([$module], null, ['fr', 'en']);

        $this->assertSame('/fr/boutique/a-propos/', $router->url('mapped-module:about'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("has no path for locale 'en'");
        $router->url('mapped-module:about', locale: 'en');
    }

    public function testMissingMountLocaleFailsAtGeneration(): void
    {
        $module = (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount(['fr' => 'boutique'])
            ->localized()
            ->withoutConventionRouting()
            ->routes(function (Routes $routes): void {
                $routes->get('/item', RouteHandlerFixture::class)->name('item');
            });
        $router = new Router([$module], null, ['fr', 'en']);

        $this->assertSame('/fr/boutique/item/', $router->url('mapped-module:item'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("has no mount for locale 'en'");
        $router->url('mapped-module:item', locale: 'en');
    }

    public function testDuplicateRouteNameAcrossTablesFailsAtGeneration(): void
    {
        $module = $this
            ->module()
            ->routes(function (Routes $routes): void {
                $routes->get('/first', RouteHandlerFixture::class)->name('same');
            })
            ->routes(function (Routes $routes): void {
                $routes->get('/second', RouteHandlerFixture::class)->name('same');
            });
        $router = new Router([$module]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Duplicate route name 'mapped-module:same'");
        $router->url('mapped-module:same');
    }

    public function testDuplicateRouteNameBetweenTableAndClaimFailsAtGeneration(): void
    {
        $module = $this
            ->module()
            ->routes(function (Routes $routes): void {
                $routes->get('/first', RouteHandlerFixture::class)->name('same');
            })
            ->claim('/extra', function (Routes $routes): void {
                $routes->get('/second', RouteHandlerFixture::class)->name('same');
            });
        $router = new Router([$module]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Duplicate route name 'mapped-module:same'");
        $router->url('mapped-module:same');
    }

    public function testLocalizedModuleRequiresItsLocaleWithASingleLocale(): void
    {
        $module = (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount('boutique')
            ->localized()
            ->withoutConventionRouting()
            ->routes(function (Routes $routes): void {
                $routes->get('/produit/{id}', [RouteHandlerFixture::class, 'show'])->where('id', '\d+')->name('product');
            });
        $router = new Router([$module], null, ['fr']);

        // The documented canonical url keeps working
        $route = $this->match($router, '/fr/boutique/produit/7/');
        $this->assertSame([7], $route->params);

        // Without prefix it redirects instead of answering 200
        try {
            $this->match($router, '/boutique/produit/7/');
            $this->fail('A redirect was expected');
        } catch (RedirectException $e) {
            $this->assertSame('/fr/boutique/produit/7/', $e->getUrl());
        }
    }

    public function testLocalePrefixIsLowercaseCanonical(): void
    {
        $module = (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount('shop')
            ->withoutConventionRouting()
            ->routes(function (Routes $routes): void {
                $routes->get('/item', RouteHandlerFixture::class);
            });
        $router = new Router([$module], null, ['fr', 'en']);

        try {
            $this->match($router, '/FR/shop/item/');
            $this->fail('A redirect was expected');
        } catch (RedirectException $e) {
            $this->assertSame('/fr/shop/item/', $e->getUrl());
        }
    }
}
