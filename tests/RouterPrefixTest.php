<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\Module;
use Kaly\Http\Exception\RedirectException;
use Kaly\Router\RedirectUris;
use Kaly\Router\ResolverInterface;
use Kaly\Router\Route;
use Kaly\Router\RouteGenerationException;
use Kaly\Router\Router;
use Kaly\Router\RouteRequest;
use Kaly\Router\Routes;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Mocks\RouteHandlerFixture;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TestModule\Controller\AliasController;
use TestModule\Controller\IndexController;

class RouterPrefixTest extends TestCase
{
    /** @return array<string, array{TrailingSlash, string}> */
    public static function slashPolicies(): array
    {
        return [
            'preserve' => [TrailingSlash::Preserve, ''],
            'add' => [TrailingSlash::Add, '/'],
            'remove' => [TrailingSlash::Remove, ''],
        ];
    }

    #[DataProvider('slashPolicies')]
    public function testMountedAndNestedClaimUrlsRoundTripToTheirOwnTables(TrailingSlash $policy, string $slash): void
    {
        $module = (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount('shop')
            ->withoutConventionRouting()
            ->routes(static function (Routes $routes): void {
                $routes->get('/items/{id}', [RouteHandlerFixture::class, 'show'])->name('mounted');
            })
            ->claim('/catalog', static function (Routes $routes): void {
                $routes->get('/items/{id}', [RouteHandlerFixture::class, 'show'])->name('catalog');
            })
            ->claim('/catalog/v2', static function (Routes $routes): void {
                $routes
                    ->prefix('/items')
                    ->group(static function (Routes $routes): void {
                        $routes->get('/{id}', [RouteHandlerFixture::class, 'show'])->name('versioned');
                    });
            });
        $router = new Router([$module], trailingSlash: $policy);

        foreach (['mounted' => '/shop', 'catalog' => '/catalog', 'versioned' => '/catalog/v2'] as $name => $prefix) {
            $url = $router->url('mapped-module:' . $name, ['id' => 7, 'ref' => 'home']);
            $this->assertSame($prefix . '/items/7' . $slash . '?ref=home', $url);
            $route = $router->match(new ServerRequest('GET', $url));
            $this->assertSame('mapped-module:' . $name, $route->name);
            $this->assertSame('MappedModule', $route->module);
            $this->assertSame([7], $route->params);
        }
    }

    /** @return array<string, array{string, string, string}> */
    public static function localizedEntries(): array
    {
        return [
            'French mount' => ['FR', 'mounted', '/fr/boutique/produit/7/'],
            'English mount' => ['EN', 'mounted', '/en/shop/item/7/'],
            'French claim' => ['FR', 'claimed', '/fr/api/v2/fr/catalogue/produit/7/'],
            'English claim' => ['EN', 'claimed', '/en/api/v2/en/catalog/item/7/'],
        ];
    }

    #[DataProvider('localizedEntries')]
    public function testLocalizedMountsAndClaimsGenerateTheMatchingLanguage(string $locale, string $name, string $expected): void
    {
        $declare = static function (Routes $routes) use ($name): void {
            $routes->get(['fr' => '/produit/{id}', 'en' => '/item/{id}'], [RouteHandlerFixture::class, 'show'])->name($name);
        };
        $module = (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount(['fr' => 'boutique', 'en' => 'shop'])
            ->localized()
            ->withoutConventionRouting();
        if ($name === 'mounted') {
            $module->routes($declare);
        } else {
            $module->claim(['fr' => '/api/v2/fr/catalogue', 'en' => '/api/v2/en/catalog'], $declare);
        }
        $router = new Router([$module], locales: ['fr', 'en'], trailingSlash: TrailingSlash::Add, localePrefixes: true);

        $url = $router->url('mapped-module:' . $name, ['id' => 7, 'ref' => 'a b'], $locale);
        $this->assertSame($expected . '?ref=a+b', $url);
        $route = $router->match(new ServerRequest('GET', $url));
        $this->assertSame('mapped-module:' . $name, $route->name);
        $this->assertSame([7], $route->params);
        $this->assertSame(strtolower($locale), $route->locale);
        $this->assertTrue($route->localeExplicit);
    }

    /** @return array<string, array{bool, string, string}> */
    public static function resolverPrefixes(): array
    {
        return [
            'root' => [false, '/', ''],
            'mounted' => [false, 'shop', '/shop'],
            'localized mount' => [true, 'shop', '/fr/shop'],
            'localized root' => [true, '/', '/fr'],
        ];
    }

    #[DataProvider('resolverPrefixes')]
    public function testCustomResolverRedirectsKeepTheirConsumedPrefix(bool $localized, string $mount, string $prefix): void
    {
        $module = (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount($mount)
            ->localized($localized)
            ->withoutConventionRouting()
            ->resolver(new class implements ResolverInterface {
                public function resolve(RouteRequest $request): Route
                {
                    if ($request->segments === ['start']) {
                        throw new RedirectException(RedirectUris::toPath(
                            $request->request,
                            $request->prefix . '/finish',
                            TrailingSlash::Preserve,
                        ));
                    }
                    return $request->route(RouteHandlerFixture::class);
                }
            });
        $router = new Router([$module], locales: ['fr', 'en'], localePrefixes: $localized);

        try {
            $router->match(new ServerRequest('POST', 'https://example.test' . $prefix . '/start?ref=home'));
            $this->fail('The resolver must redirect to its own entry point');
        } catch (RedirectException $redirect) {
            $this->assertSame(307, $redirect->status());
            $this->assertSame($prefix . '/finish?ref=home', $redirect->getUrl());
            $route = $router->match(new ServerRequest('POST', $redirect->getUrl()));
            $this->assertSame('MappedModule', $route->module);
            $this->assertSame('fr', $route->locale);
            $this->assertSame($localized, $route->localeExplicit);
        }
    }

    public function testDisabledLocalePrefixesPreserveALiteralLocaleMountAndClaim(): void
    {
        $module = (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount('fr')
            ->withoutConventionRouting()
            ->routes(static function (Routes $routes): void {
                $routes->get('/items/{id}', [RouteHandlerFixture::class, 'show'])->name('mounted');
            })
            ->claim('/en/catalog', static function (Routes $routes): void {
                $routes->get('/items/{id}', [RouteHandlerFixture::class, 'show'])->name('claimed');
            });
        $router = new Router([$module], locales: ['fr', 'en']);

        foreach (['mounted' => '/fr', 'claimed' => '/en/catalog'] as $name => $prefix) {
            $url = $router->url('mapped-module:' . $name, ['id' => 7], 'en');
            $this->assertSame($prefix . '/items/7', $url);
            $route = $router->match(new ServerRequest('GET', $url));
            $this->assertSame('mapped-module:' . $name, $route->name);
            $this->assertSame([7], $route->params);
            $this->assertSame('fr', $route->locale);
            $this->assertFalse($route->localeExplicit);
        }
    }

    public function testUnknownGenerationLocaleIsRejected(): void
    {
        $module = (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount('shop')
            ->withoutConventionRouting()
            ->routes(static function (Routes $routes): void {
                $routes->get('/items/{id}', [RouteHandlerFixture::class, 'show'])->name('item');
            });
        $router = new Router([$module], locales: ['fr', 'en']);

        $this->expectException(RouteGenerationException::class);
        $this->expectExceptionMessage("Invalid locale 'de'");
        $router->url('mapped-module:item', ['id' => 7], 'DE');
    }

    public function testConventionalRedirectsPreserveTheLocaleMountAndQuery(): void
    {
        $module = (new Module(__DIR__ . '/modules/TestModule'))
            ->mount('shop')
            ->localized();
        $module->autoloadFiles();
        $router = new Router([$module], locales: ['fr', 'en'], trailingSlash: TrailingSlash::Add, localePrefixes: true);

        try {
            $router->match(new ServerRequest('GET', '/fr/shop/index/typedInt/7/?ref=home'));
            $this->fail('The action spelling must be canonicalized');
        } catch (RedirectException $redirect) {
            $this->assertSame('/fr/shop/index/typed-int/7/?ref=home', $redirect->getUrl());
            $route = $router->match(new ServerRequest('GET', $redirect->getUrl()));
            $this->assertSame(IndexController::class, $route->controller);
            $this->assertSame([7], $route->params);
            $this->assertSame('fr', $route->locale);
        }

        $this->assertSame('/fr/shop/index/typed-int/7/', $router->urlFor([IndexController::class, 'typedInt'], [7], 'FR'));
    }

    public function testAClaimOwnedActionSuggestsItsSingleRouteNameAcrossLocales(): void
    {
        $module = (new Module(__DIR__ . '/modules/TestModule'))
            ->mount('shop')
            ->localized()
            ->claim(['fr' => '/catalogue/v2', 'en' => '/catalog/v2'], static function (Routes $routes): void {
                $routes->get('/items/{id}', [AliasController::class, 'item'])->name('catalog-item');
            });
        $module->autoloadFiles();
        $router = new Router([$module], locales: ['fr', 'en'], localePrefixes: true);
        $this->assertSame('/fr/catalogue/v2/items/7', $router->url('test-module:catalog-item', ['id' => 7], 'fr'));
        $this->assertSame('/en/catalog/v2/items/7', $router->url('test-module:catalog-item', ['id' => 7], 'en'));

        $this->expectException(RouteGenerationException::class);
        $this->expectExceptionMessage("use route 'test-module:catalog-item'");
        $router->urlFor([AliasController::class, 'item'], [7], 'fr');
    }
}
