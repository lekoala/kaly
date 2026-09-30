<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\Ex;
use Kaly\Core\Module;
use Kaly\Http\MethodNotAllowedException;
use Kaly\Http\RedirectException;
use Kaly\Router\RedirectUris;
use Kaly\Router\ResolverInterface;
use Kaly\Router\Route;
use Kaly\Router\Router;
use Kaly\Router\RouteRequest;
use Kaly\Router\Routes;
use Kaly\Tests\Mocks\RouteHandlerFixture;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\ServerRequest as BaseServerRequest;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TestModule\Controller\AliasController;

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

    public function testACustomResolverAtPriorityZeroFollowsTheTableWhenDeclaredAfter(): void
    {
        // routes() then resolver() at equal priority: the table is first,
        // because that is the order config.php reads as
        $after = $this
            ->module()
            ->routes(function (Routes $routes): void {
                $routes->get('/shared', RouteHandlerFixture::class);
            })
            ->resolver(new class implements ResolverInterface {
                public function resolve(RouteRequest $request): ?Route
                {
                    return $request->segments === ['shared'] ? $request->route(AliasController::class, 'index') : null;
                }
            });

        // The table wins /shared, the custom resolver is never consulted
        $route = $this->match(new Router([$after]), '/shop/shared/');
        $this->assertSame(RouteHandlerFixture::class, $route->controller);

        // The mirror order: declared before, it goes first
        $before = $this
            ->module()
            ->resolver(new class implements ResolverInterface {
                public function resolve(RouteRequest $request): ?Route
                {
                    return $request->segments === ['shared'] ? $request->route(AliasController::class, 'index') : null;
                }
            })
            ->routes(function (Routes $routes): void {
                $routes->get('/shared', RouteHandlerFixture::class);
            });

        $route = $this->match(new Router([$before]), '/shop/shared/');
        $this->assertSame(AliasController::class, $route->controller);
    }

    public function testAPriorityStillWinsOverTheDeclarationOrder(): void
    {
        $module = $this
            ->module()
            ->routes(function (Routes $routes): void {
                $routes->get('/shared', RouteHandlerFixture::class);
            })
            ->resolver(new class implements ResolverInterface {
                public function resolve(RouteRequest $request): ?Route
                {
                    return $request->segments === ['shared'] ? $request->route(AliasController::class, 'index') : null;
                }
            }, priority: -10);

        $route = $this->match(new Router([$module]), '/shop/shared/');
        $this->assertSame(AliasController::class, $route->controller);
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

    public function testDuplicateRouteNameAcrossCallsFailsAtCompileTime(): void
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

        // Both calls feed one table: the duplicate fails when it compiles
        $this->expectException(Ex::class);
        $this->expectExceptionMessage("Duplicate route name 'same'");
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

    public function testTable405IsAuthoritativeOverTheConvention(): void
    {
        $module = new Module(__DIR__ . '/modules/TestModule');
        $module->autoloadFiles();
        $module->routes(function (Routes $routes): void {
            $routes->get('/alias/hello', [AliasController::class, 'hello']);
        })->routes(function (Routes $routes): void {
            $routes->get('/alias/priority', [AliasController::class, 'priority']);
        });
        // The convention stays enabled: it would run hello() for any method
        $router = new Router([$module]);

        // Both calls feed one table
        $this->assertNotNull($this->match($router, '/test-module/alias/hello/'));
        $this->assertNotNull($this->match($router, '/test-module/alias/priority/'));

        // ... but POST never falls through to the convention
        try {
            $this->match($router, '/test-module/alias/hello/', 'POST');
            $this->fail('A 405 was expected');
        } catch (MethodNotAllowedException $e) {
            $this->assertSame(['GET'], $e->getAllowedMethods());
        }
    }

    public function testMountPerLocaleWithoutLocalizedFailsAtBoot(): void
    {
        $module = (new Module(__DIR__ . '/modules/MappedModule'))->mount(['fr' => 'boutique']);

        $this->expectException(Ex::class);
        $this->expectExceptionMessage('is not localized');
        new Router([$module], null, ['fr', 'en']);
    }

    public function testClaimPerLocaleWithoutLocalizedFailsAtBoot(): void
    {
        $module = (new Module(__DIR__ . '/modules/MappedModule'))->claim([
            'fr' => '/a-propos',
            'en' => '/about',
        ], function (Routes $routes): void {
            $routes->get('/', RouteHandlerFixture::class);
        });

        $this->expectException(Ex::class);
        $this->expectExceptionMessage('is not localized');
        new Router([$module], null, ['fr', 'en']);
    }

    public function testLocalizedWithoutAppLocalesFailsAtBoot(): void
    {
        $module = (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount('shop')
            ->localized();

        $this->expectException(Ex::class);
        $this->expectExceptionMessage('declares no locales');
        new Router([$module], null, []);
    }

    public function testPathsPerLocaleWithoutLocalizedFailAtCompileTime(): void
    {
        $module = (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount('shop')
            ->withoutConventionRouting()
            ->routes(function (Routes $routes): void {
                $routes
                    ->get(['fr' => '/article/{id}', 'en' => '/post/{id}'], [RouteHandlerFixture::class, 'show'])
                    ->where('id', '\d+')
                    ->name('item');
            });
        // Building the router is fine: the table compiles on first use
        $router = new Router([$module], null, ['fr', 'en']);

        try {
            $router->url('mapped-module:item', ['id' => 7], 'en');
            $this->fail('An incoherent locale declaration was expected to fail');
        } catch (Ex $e) {
            $this->assertStringContainsString('is not localized', $e->getMessage());
        }
    }
}
