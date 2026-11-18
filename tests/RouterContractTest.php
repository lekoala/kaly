<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\Module;
use Kaly\Http\Exception\MethodNotAllowedException;
use Kaly\Router\ResolverInterface;
use Kaly\Router\Route;
use Kaly\Router\RouteGenerationException;
use Kaly\Router\RouteNotFoundException;
use Kaly\Router\Router;
use Kaly\Router\RouteRequest;
use Kaly\Router\Routes;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Mocks\SearchInput;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TestModule\Controller\AliasController;
use TestModule\Controller\DemoController;
use TestModule\Controller\IndexController;
use TestModule\Controller\InputController;
use TestModule\Controller\ShopController;
use TestModule\Controller\SlugController;

/**
 * The contracts of the router, frozen before 1.0: what a generated url
 * matches, how non canonical urls redirect, which resolver answers, and what
 * generation refuses. Each test states a promise of docs/routing.md; a
 * failure here is a behavior change for applications, not a refactoring.
 */
class RouterContractTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    // #region Fixtures

    /**
     * A module of the TestModule fixture with a representative table:
     * placeholders, a requirement, a POST, a generate-only default and a
     * trailing RequestInput.
     */
    private function shopModule(): Module
    {
        $module = (new Module(__DIR__ . '/modules/TestModule'))
            ->mount('shop')
            ->routes(static function (Routes $routes): void {
                $routes->get('/produit/{slug}', [ShopController::class, 'show'])->name('product');
                $routes->get('/item/{id}', [AliasController::class, 'item'])->name('item')->where('id', '\d+');
                $routes->post('/save', [AliasController::class, 'savePost'])->name('save');
                $routes->get('/featured/{tab}', [ShopController::class, 'featured'])->name('featured')->default('tab', 'all');
                $routes->get('/edit/{id}', [InputController::class, 'edit'])->name('edit');
                $routes->get('/flag/{value}', [IndexController::class, 'typedBool'])->name('flag');
            })
            ->claim('/aide', static function (Routes $routes): void {
                $routes->get('/{slug}', [ShopController::class, 'show'])->name('help');
            });
        $module->autoloadFiles();
        return $module;
    }

    private static function request(string $url, string $method = 'GET'): ServerRequest
    {
        return new ServerRequest($method, new Uri($url));
    }

    private static function resolverFor(string $path, string $marker): ResolverInterface
    {
        return new class($path, $marker) implements ResolverInterface {
            public function __construct(
                private string $path,
                private string $marker,
            ) {}

            public function resolve(RouteRequest $request): ?Route
            {
                if ($request->path() !== $this->path) {
                    return null;
                }
                return $request->route(ShopController::class, 'show', [$this->marker]);
            }
        };
    }

    // #endregion

    // #region Round trip

    /**
     * @return iterable<string,array{string,array<string,mixed>,string,string,string,list<mixed>}>
     */
    public static function namedRoutes(): iterable
    {
        yield 'placeholder' => ['test-module:product', ['slug' => 'chaise'], 'GET', ShopController::class, 'show', ['chaise']];
        yield 'typed requirement' => ['test-module:item', ['id' => 42], 'GET', AliasController::class, 'item', [42]];
        yield 'post' => ['test-module:save', [], 'POST', AliasController::class, 'savePost', []];
        yield 'generate-only default' => ['test-module:featured', [], 'GET', ShopController::class, 'featured', ['all']];
        yield 'trailing input' => ['test-module:edit', ['id' => 3], 'GET', InputController::class, 'edit', [3]];
        yield 'bool true' => ['test-module:flag', ['value' => true], 'GET', IndexController::class, 'typedBool', [true]];
        yield 'bool false' => ['test-module:flag', ['value' => false], 'GET', IndexController::class, 'typedBool', [false]];
        yield 'claim' => ['test-module:help', ['slug' => 'faq'], 'GET', ShopController::class, 'show', ['faq']];
    }

    /**
     * @param array<string,mixed> $params
     * @param list<mixed> $expected
     */
    #[DataProvider('namedRoutes')]
    public function testAGeneratedUrlMatchesItsRoute(
        string $name,
        array $params,
        string $method,
        string $controller,
        string $action,
        array $expected,
    ): void {
        foreach ([TrailingSlash::Add, TrailingSlash::Remove, TrailingSlash::Preserve] as $policy) {
            $router = new Router([$this->shopModule()], null, [], $policy);

            $route = $router->match(self::request($router->url($name, $params), $method));

            $this->assertSame($controller, $route->controller);
            $this->assertSame($action, $route->action);
            $this->assertSame($expected, $route->params);
            $this->assertSame($name, $route->name);
        }
    }

    public function testExtraParamsBecomeAQueryStringIgnoredByMatching(): void
    {
        $router = new Router([$this->shopModule()], null, [], TrailingSlash::Remove);

        $url = $router->url('test-module:item', ['id' => 42, 'page' => 2]);

        $this->assertSame('/shop/item/42?page=2', $url);
        $this->assertSame([42], $router->match(self::request($url))->params);
    }

    public function testATrailingInputIsCarriedByTheRouteNotByTheUrl(): void
    {
        $router = new Router([$this->shopModule()], null, [], TrailingSlash::Remove);

        $route = $router->match(self::request($router->url('test-module:edit', ['id' => 3])));

        $this->assertSame(SearchInput::class, $route->inputClass);
    }

    /**
     * @return iterable<string,array{array<mixed>,list<mixed>,string,string,string,list<mixed>,?string}>
     */
    public static function conventionalActions(): iterable
    {
        yield 'string argument' => [[DemoController::class, 'index'], ['x y'], 'GET', DemoController::class, 'index', ['x y'], null];
        yield 'verb suffix' => [[DemoController::class, 'moveItemPost'], [5], 'POST', DemoController::class, 'moveItemPost', [5], null];
        yield 'typed int, dashed' => [[IndexController::class, 'typedInt'], [7], 'GET', IndexController::class, 'typedInt', [7], null];
        yield 'invokable' => [[SlugController::class], ['hello'], 'GET', SlugController::class, '__invoke', ['hello'], null];
        yield 'variadic' => [[DemoController::class, 'arr'], ['a', 'b'], 'GET', DemoController::class, 'arr', ['a', 'b'], null];
        yield 'trailing input' => [
            [InputController::class, 'pagedSearch'],
            [3],
            'GET',
            InputController::class,
            'pagedSearch',
            [3],
            SearchInput::class,
        ];
    }

    /**
     * @param array<mixed> $handler
     * @param list<mixed> $args
     * @param list<mixed> $expected
     */
    #[DataProvider('conventionalActions')]
    public function testAConventionalUrlMatchesItsAction(
        array $handler,
        array $args,
        string $method,
        string $controller,
        string $action,
        array $expected,
        ?string $inputClass,
    ): void {
        foreach ([TrailingSlash::Add, TrailingSlash::Remove, TrailingSlash::Preserve] as $policy) {
            $router = new Router([$this->shopModule()], null, [], $policy);

            $route = $router->match(self::request($router->urlFor($handler, $args), $method));

            $this->assertSame($controller, $route->controller);
            $this->assertSame($action, $route->action);
            $this->assertSame($expected, $route->params);
            $this->assertSame($inputClass, $route->inputClass);
        }
    }

    // #endregion

    public function testConventionalBoolArgumentsRoundTrip(): void
    {
        foreach ([true, false] as $value) {
            $module = (new Module(__DIR__ . '/modules/TestModule'))->mount('shop');
            $module->autoloadFiles();
            $router = new Router([$module], null, [], TrailingSlash::Add);

            // (string) false is empty: both booleans have a segment
            $url = $router->urlFor([IndexController::class, 'typedBool'], [$value]);

            $this->assertSame('/shop/index/typed-bool/' . ($value ? '1' : '0') . '/', $url);
            $this->assertSame([$value], $router->match(self::request($url))->params);
        }
    }

    // #region Canonicalization

    private function app(TrailingSlash $policy): App
    {
        return App::create(__DIR__)->routing($policy, true)->boot();
    }

    private static function handle(App $app, string $path, string $method): ResponseInterface
    {
        return $app->handle(self::request($path, $method));
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function nonCanonicalUrls(): iterable
    {
        yield 'mount case' => ['/Test-Module/alias/save', '/test-module/alias/save'];
        yield 'trailing slash' => ['/test-module/alias/save/', '/test-module/alias/save'];
        yield 'controller segment' => ['/test-module/Demo/move-item/5', '/test-module/demo/move-item/5'];
        yield 'action spelling' => ['/test-module/demo/moveItem/5', '/test-module/demo/move-item/5'];
        yield 'locale prefix case' => ['/EN/lang-module/index/index', '/en/lang-module/index/index'];
        yield 'locale prefix on a non localized module' => ['/en/test-module/alias/save', '/test-module/alias/save'];
    }

    #[DataProvider('nonCanonicalUrls')]
    public function testANonCanonicalUrlRedirectsWith307PreservingTheMethod(string $path, string $canonical): void
    {
        $app = $this->app(TrailingSlash::Remove);

        foreach (['GET', 'POST'] as $method) {
            $response = self::handle($app, $path, $method);

            // 307 obliges the client to repeat the same method: a POST is
            // never degraded to a GET by a canonical redirect
            $this->assertSame(307, $response->getStatusCode(), "{$method} {$path}");
            $this->assertSame($canonical, $response->getHeaderLine('Location'), "{$method} {$path}");
        }
    }

    public function testPreserveOnlyDisablesTheSlashPolicy(): void
    {
        $app = $this->app(TrailingSlash::Preserve);

        // Both spellings answer, nothing redirects
        $this->assertSame(200, self::handle($app, '/test-module/alias/save', 'POST')->getStatusCode());
        $this->assertSame(200, self::handle($app, '/test-module/alias/save/', 'POST')->getStatusCode());

        // The other canonicalizations still apply, and keep the slash as given
        $mount = self::handle($app, '/Test-Module/alias/save/', 'POST');
        $this->assertSame(307, $mount->getStatusCode());
        $this->assertSame('/test-module/alias/save/', $mount->getHeaderLine('Location'));

        $action = self::handle($app, '/test-module/demo/moveItem/5', 'POST');
        $this->assertSame(307, $action->getStatusCode());
        $this->assertSame('/test-module/demo/move-item/5', $action->getHeaderLine('Location'));

        $locale = self::handle($app, '/en/test-module/alias/save', 'POST');
        $this->assertSame(307, $locale->getStatusCode());
        $this->assertSame('/test-module/alias/save', $locale->getHeaderLine('Location'));
    }

    public function testACanonicalRedirectKeepsTheQueryString(): void
    {
        $response = self::handle($this->app(TrailingSlash::Remove), '/test-module/alias/save/?a=1&b=2', 'POST');

        $this->assertSame(307, $response->getStatusCode());
        $this->assertSame('/test-module/alias/save?a=1&b=2', $response->getHeaderLine('Location'));
    }

    // #endregion

    // #region Precedence

    public function testResolversRunByPriorityThenDeclarationOrder(): void
    {
        $module = (new Module(__DIR__ . '/modules/TestModule'))
            ->mount('shop')
            ->resolver(self::resolverFor('/before', 'declared-before'))
            ->resolver(self::resolverFor('/both', 'declared-before'))
            ->routes(static function (Routes $routes): void {
                $routes->get('/both', [ShopController::class, 'show'])->default('slug', 'table');
                $routes->get('/table', [ShopController::class, 'show'])->default('slug', 'table');
            })
            ->resolver(self::resolverFor('/table', 'declared-after'))
            ->resolver(self::resolverFor('/demo', 'late'), priority: 2000)
            ->resolver(self::resolverFor('/early', 'early'), priority: -10);
        $module->autoloadFiles();
        $router = new Router([$module], null, [], TrailingSlash::Remove);

        // Same priority (0): declaration order, the table at its first routes()
        $this->assertSame(['declared-before'], $router->match(self::request('/shop/both'))->params);
        $this->assertSame(['table'], $router->match(self::request('/shop/table'))->params);
        // The convention (1000) runs before a resolver of priority 2000
        $this->assertSame(DemoController::class, $router->match(self::request('/shop/demo'))->controller);
        // A lower priority runs first
        $this->assertSame(['early'], $router->match(self::request('/shop/early'))->params);
    }

    public function testATableRouteRunsBeforeTheConvention(): void
    {
        $module = (new Module(__DIR__ . '/modules/TestModule'))
            ->mount('shop')
            ->routes(static function (Routes $routes): void {
                $routes->get('/demo/func', [ShopController::class, 'health']);
            });
        $module->autoloadFiles();
        $router = new Router([$module], null, [], TrailingSlash::Remove);

        $this->assertSame(ShopController::class, $router->match(self::request('/shop/demo/func'))->controller);
        $this->assertSame(DemoController::class, $router->match(self::request('/shop/demo/hello-func'))->controller);
    }

    public function testTheLongestClaimWinsOverTheMount(): void
    {
        $shop = $this->shopModule();
        $other = (new Module(__DIR__ . '/modules/MappedModule'))->claim('/aide/contact', static function (Routes $routes): void {
            $routes->get('/', [SlugController::class, '__invoke'])->default('slug', 'contact');
        });
        $router = new Router([$shop, $other], null, [], TrailingSlash::Remove);

        $this->assertSame(SlugController::class, $router->match(self::request('/aide/contact'))->controller);
        $this->assertSame(['faq'], $router->match(self::request('/aide/faq'))->params);
    }

    // #endregion

    // #region 404 and 405

    public function testA405IsAuthoritativeOverTheFollowingResolvers(): void
    {
        $catchAll = new class implements ResolverInterface {
            public function resolve(RouteRequest $request): Route
            {
                return $request->route(ShopController::class, 'health');
            }
        };
        $module = (new Module(__DIR__ . '/modules/TestModule'))
            ->mount('shop')
            ->routes(static function (Routes $routes): void {
                // methodGet stays conventional on the same path
                $routes->post('/demo/method', [DemoController::class, 'methodPost']);
            })
            ->resolver($catchAll, priority: 500);
        $module->autoloadFiles();
        $router = new Router([$module], null, [], TrailingSlash::Remove);

        $this->assertSame('methodPost', $router->match(self::request('/shop/demo/method', 'POST'))->action);
        // The catch-all proves the following resolvers would answer otherwise
        $this->assertSame('health', $router->match(self::request('/shop/anything'))->action);

        try {
            $router->match(self::request('/shop/demo/method'));
            $this->fail('A path known for other methods must be a 405');
        } catch (MethodNotAllowedException $e) {
            $this->assertSame(405, $e->status());
            $this->assertStringContainsString('POST', $e->getResponseHeaders()['Allow'] ?? '');
        }
    }

    public function testAnUnknownUrlIsANotFoundCarryingTheReasons(): void
    {
        $module = (new Module(__DIR__ . '/modules/TestModule'))
            ->mount('shop')
            ->withoutConventionRouting();
        $module->resolver(new class implements ResolverInterface {
            public function resolve(RouteRequest $request): ?Route
            {
                throw new RouteNotFoundException('no page at this path');
            }
        });
        $router = new Router([$module], null, [], TrailingSlash::Remove);

        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionMessageMatches("/'\\/shop\\/missing' not found in module 'test-module'.*no page at this path/");
        $router->match(self::request('/shop/missing'));
    }

    // #endregion

    // #region Honest generation

    public function testUrlForAnOwnedActionNamesTheRoute(): void
    {
        $router = new Router([$this->shopModule()], null, [], TrailingSlash::Remove);

        $this->expectException(RouteGenerationException::class);
        $this->expectExceptionMessageMatches("/'test-module:item'/");
        $router->urlFor([AliasController::class, 'item'], [42]);
    }

    public function testUrlForInAModuleWithoutConventionNamesTheChoice(): void
    {
        $module = (new Module(__DIR__ . '/modules/TestModule'))
            ->mount('shop')
            ->withoutConventionRouting();
        $router = new Router([$module], null, [], TrailingSlash::Remove);

        $this->expectException(RouteGenerationException::class);
        $this->expectExceptionMessageMatches('/withoutConventionRouting\(\)/');
        $router->urlFor([DemoController::class, 'index']);
    }

    public function testAnUnknownRouteNameFails(): void
    {
        $router = new Router([$this->shopModule()], null, [], TrailingSlash::Remove);

        $this->expectException(RouteGenerationException::class);
        $this->expectExceptionMessage("Unknown route 'test-module:nope'");
        $router->url('test-module:nope');
    }

    // #endregion

    // #region Locales

    private function localizedRouter(): Router
    {
        $module = (new Module(__DIR__ . '/modules/TestModule'))
            ->mount(['fr' => 'boutique', 'en' => 'shop'])
            ->localized()
            ->routes(static function (Routes $routes): void {
                $routes->get(['fr' => '/produit/{slug}', 'en' => '/product/{slug}'], [ShopController::class, 'show'])->name('product');
            });
        $module->autoloadFiles();
        return new Router([$module], null, ['fr', 'en'], TrailingSlash::Remove, localePrefixes: true);
    }

    public function testRoundTripPerLocale(): void
    {
        $router = $this->localizedRouter();

        $expected = ['fr' => '/fr/boutique/produit/chaise', 'en' => '/en/shop/product/chaise'];
        foreach ($expected as $locale => $url) {
            $this->assertSame($url, $router->url('test-module:product', ['slug' => 'chaise'], $locale));
            $route = $router->match(self::request($url));
            $this->assertSame($locale, $route->locale);
            $this->assertSame(['chaise'], $route->params);

            $conventional = $router->urlFor([DemoController::class, 'index'], ['x'], $locale);
            $route = $router->match(self::request($conventional));
            $this->assertSame($locale, $route->locale);
            $this->assertSame(['x'], $route->params);
        }
    }

    public function testAMissingMountForALocaleFailsGeneration(): void
    {
        $module = (new Module(__DIR__ . '/modules/TestModule'))->mount(['fr' => 'boutique'])->localized();
        $router = new Router([$module], null, ['fr', 'en'], TrailingSlash::Remove, localePrefixes: true);

        $this->assertSame('/fr/boutique/demo', $router->urlFor([DemoController::class, 'index'], [], 'fr'));

        $this->expectException(RouteGenerationException::class);
        $this->expectExceptionMessage("Module 'test-module' has no mount for locale 'en'");
        $router->urlFor([DemoController::class, 'index'], [], 'en');
    }

    // #endregion

    // #region Encoding

    /**
     * @return iterable<string,array{string}>
     */
    public static function segments(): iterable
    {
        yield 'space' => ['a b'];
        yield 'accents' => ['Été à-b'];
        yield 'non latin' => ['日本語'];
        yield 'slash' => ['a/b'];
        yield 'question mark' => ['a?b'];
        yield 'hash' => ['a#b'];
        yield 'percent' => ['100%'];
        yield 'plus' => ['a+b'];
        yield 'unreserved' => ['-_.~'];
        yield 'mixed case' => ['CamelCase'];
    }

    #[DataProvider('segments')]
    public function testAValueSurvivesTheRoundTrip(string $value): void
    {
        $router = new Router([$this->shopModule()], null, [], TrailingSlash::Remove);

        $named = $router->url('test-module:product', ['slug' => $value]);
        $this->assertSame([$value], $router->match(self::request($named))->params);

        $conventional = $router->urlFor([SlugController::class], [$value]);
        $this->assertSame([$value], $router->match(self::request($conventional))->params);
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function dotSegments(): iterable
    {
        yield 'current' => ['.'];
        yield 'parent' => ['..'];
    }

    #[DataProvider('dotSegments')]
    public function testADotSegmentValueIsRefusedByGeneration(string $value): void
    {
        // Clients remove dot segments: the url would reach another resource
        $router = new Router([$this->shopModule()], null, [], TrailingSlash::Remove);

        try {
            $router->url('test-module:product', ['slug' => $value]);
            $this->fail('url() must refuse a dot segment');
        } catch (RouteGenerationException $e) {
            $this->assertStringContainsString("'slug'", $e->getMessage());
        }

        try {
            $router->urlFor([SlugController::class], [$value]);
            $this->fail('urlFor() must refuse a dot segment');
        } catch (RouteGenerationException $e) {
            $this->assertStringContainsString(SlugController::class, $e->getMessage());
        }

        // A query string value is not a path segment
        $this->assertSame('/shop/item/1?page=' . rawurlencode($value), $router->url('test-module:item', ['id' => 1, 'page' => $value]));
    }

    public function testValuesMerelyContainingDotsAreGenerated(): void
    {
        $router = new Router([$this->shopModule()], null, [], TrailingSlash::Remove);

        foreach (['.env', '...', 'article.html', '.a.'] as $value) {
            $this->assertSame([$value], $router->match(self::request($router->url('test-module:product', ['slug' => $value])))->params);
            $this->assertSame([$value], $router->match(self::request($router->urlFor([SlugController::class], [$value])))->params);
        }
    }

    // #endregion

    // #region Dynamic resolution

    public function testADynamicResolverAnswersArbitraryUrlsWithoutCompilingTheTable(): void
    {
        $pages = ['about-us' => 'About us', 'a/deep/page' => 'A deep page'];
        $cms = new class($pages) implements ResolverInterface {
            /** @param array<string,string> $pages */
            public function __construct(
                private array $pages,
            ) {}

            public function resolve(RouteRequest $request): ?Route
            {
                $title = $this->pages[implode('/', $request->segments)] ?? null;
                return $title === null ? null : $request->route(ShopController::class, 'show', [$title]);
            }
        };
        // Links come from an application service, not from the router
        $links = static fn(string $path): string => '/pages/' . $path;

        $module = (new Module(__DIR__ . '/modules/TestModule'))
            ->mount('pages')
            ->resolver($cms)
            ->routes(static function (Routes $routes): void {
                // Broken on purpose: compiling the table would throw
                $routes->get('/broken', [ShopController::class, 'missingAction']);
            });
        $module->autoloadFiles();
        $router = new Router([$module], null, [], TrailingSlash::Remove);

        foreach ($pages as $path => $title) {
            $this->assertSame([$title], $router->match(self::request($links($path)))->params);
        }
    }

    // #endregion

    // #region Claims

    public function testAClaimIsAnIsland(): void
    {
        $catchAll = new class implements ResolverInterface {
            public function resolve(RouteRequest $request): Route
            {
                return $request->route(ShopController::class, 'health');
            }
        };
        $module = (new Module(__DIR__ . '/modules/TestModule'))
            ->mount('shop')
            ->resolver($catchAll)
            ->claim('/aide', static function (Routes $routes): void {
                $routes->get('/faq', [ShopController::class, 'show'])->default('slug', 'faq');
            });
        $module->autoloadFiles();
        $router = new Router([$module], null, [], TrailingSlash::Remove);

        $this->assertSame('show', $router->match(self::request('/aide/faq'))->action);
        // The catch-all answers below the mount, not below the claim
        $this->assertSame('health', $router->match(self::request('/shop/anything'))->action);
        foreach (['/aide/anything', '/aide/demo/func', '/aide'] as $path) {
            try {
                $router->match(self::request($path));
                $this->fail("'{$path}' must only be resolved by the claim table");
            } catch (RouteNotFoundException $e) {
                $this->assertStringContainsString("not found in module 'test-module'", $e->getMessage());
            }
        }
    }

    // #endregion
}
