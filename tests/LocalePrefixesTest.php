<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\Module;
use Kaly\Ex;
use Kaly\Router\Router;
use Kaly\Router\Routes;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Mocks\LocaleConsultFixture;
use Kaly\Tests\Mocks\RouteHandlerFixture;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;

class LocalePrefixesTest extends TestCase
{
    /**
     * @return array{0:string,1:array<int<0,max>|string,mixed>,2:?string}
     */
    private function match(Router $router, string $path): array
    {
        $route = $router->match(HttpFactory::createRequestFromGlobals()->withUri(new Uri($path)));
        return [$route->controller, $route->params, $route->locale];
    }

    public function testLocalePlaceholderIsAnOrdinaryParamWithoutPrefixes(): void
    {
        $module = (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount('/')
            ->withoutConventionRouting()
            ->routes(function (Routes $routes): void {
                $routes->get('/{locale}/consultations', [LocaleConsultFixture::class, 'consult']);
                $routes->get('/fr/special', RouteHandlerFixture::class);
            });
        $router = new Router([$module], null, ['fr', 'nl', 'en']);

        // No consumption, no redirect: the placeholder reaches the action
        [$controller, $params] = $this->match($router, '/nl/consultations');
        $this->assertSame(LocaleConsultFixture::class, $controller);
        $this->assertSame(['nl'], $params);

        // A literal /fr/... route is not consumed as a locale prefix either
        [$controller] = $this->match($router, '/fr/special');
        $this->assertSame(RouteHandlerFixture::class, $controller);
    }

    public function testMountNamedAfterALocaleIsNotConsumedWithoutPrefixes(): void
    {
        $module = (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount('fr')
            ->withoutConventionRouting()
            ->routes(function (Routes $routes): void {
                $routes->get('/hello', RouteHandlerFixture::class);
            });
        $router = new Router([$module], null, ['fr', 'nl', 'en']);

        [$controller] = $this->match($router, '/fr/hello');
        $this->assertSame(RouteHandlerFixture::class, $controller);
    }

    public function testLocalizedModuleRequiresPrefixes(): void
    {
        $module = (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount('shop')
            ->localized();

        $this->expectException(Ex::class);
        $this->expectExceptionMessage('locale prefixes are disabled');
        new Router([$module], null, ['fr', 'en']);
    }

    public function testTranslatedPathsMatchByPathWithoutPrefixes(): void
    {
        $module = (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount('shop')
            ->withoutConventionRouting()
            ->routes(function (Routes $routes): void {
                $routes->get(['fr' => '/medecins', 'nl' => '/artsen'], RouteHandlerFixture::class)->name('docs');
                $routes
                    ->get(['fr' => '/article/{id}', 'nl' => '/artikel/{id}'], [RouteHandlerFixture::class, 'show'])
                    ->where('id', '\d+')
                    ->name('item');
            });
        $router = new Router([$module], null, ['fr', 'nl', 'en']);

        [$controller, , $locale] = $this->match($router, '/shop/medecins');
        $this->assertSame(RouteHandlerFixture::class, $controller);
        $this->assertSame('fr', $locale);

        [$controller, $params, $locale] = $this->match($router, '/shop/artikel/7');
        $this->assertSame(RouteHandlerFixture::class, $controller);
        $this->assertSame([7], $params);
        $this->assertSame('nl', $locale);

        // Generation stays symmetric with matching, without any url prefix
        $this->assertSame('/shop/medecins', $router->url('mapped-module:docs', locale: 'fr'));
        $this->assertSame('/shop/artikel/7', $router->url('mapped-module:item', ['id' => 7], 'nl'));
    }

    public function testExplicitPrefixesKeepTheirContract(): void
    {
        $module = (new Module(__DIR__ . '/modules/MappedModule'))
            ->mount('shop')
            ->localized()
            ->withoutConventionRouting()
            ->routes(function (Routes $routes): void {
                $routes->get('/hello', RouteHandlerFixture::class)->name('hello');
            });
        $router = new Router([$module], null, ['fr', 'en'], TrailingSlash::Add, true);

        [$controller, , $locale] = $this->match($router, '/fr/shop/hello/');
        $this->assertSame(RouteHandlerFixture::class, $controller);
        $this->assertSame('fr', $locale);
        $this->assertSame('/fr/shop/hello/', $router->url('mapped-module:hello'));
    }
}
