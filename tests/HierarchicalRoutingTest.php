<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\Module;
use Kaly\Ex;
use Kaly\Router\RouteNotFoundException;
use Kaly\Router\Router;
use Kaly\Router\RouterInterface;
use Kaly\Router\Routes;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TestModule\Controller\IndexController;

/**
 * Every url belongs to one module, which resolves it alone
 * (see tests/data/apps/routing/modules/*\/config.php)
 */
class HierarchicalRoutingTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $this->app = App::create(__DIR__ . '/data/apps/routing', false)
            ->locales(['fr', 'en'])
            ->boot();
    }

    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    private function get(string $path): ResponseInterface
    {
        return $this->app->handle(HttpFactory::createRequestFromGlobals()->withUri(new Uri($path)));
    }

    private function body(string $path): string
    {
        $response = $this->get($path);
        $this->assertSame(200, $response->getStatusCode(), "{$path}: " . $response->getHeaderLine('Location'));
        return (string) $response->getBody();
    }

    private function router(): RouterInterface
    {
        return $this->app->container()->get(RouterInterface::class);
    }

    public function testTheDefaultModuleAnswersWithoutPrefix(): void
    {
        $this->assertSame('home:fr', $this->body('/'));
        $this->assertSame('home:en', $this->body('/en/'));
        // The default locale alone is the home page
        $this->assertSame('/', $this->get('/fr/')->getHeaderLine('Location'));
    }

    public function testOneRouteHasOnePathPerLocale(): void
    {
        $this->assertSame('contact:fr', $this->body('/fr/a-propos/'));
        $this->assertSame('contact:en', $this->body('/en/about/'));
        // Each path only exists in its own locale
        $this->assertSame(404, $this->get('/fr/about/')->getStatusCode());
    }

    public function testAModuleCanHaveOneMountPerLocale(): void
    {
        $this->assertSame('product:velo', $this->body('/fr/boutique/produit/velo/'));
        $this->assertSame('product:bike', $this->body('/en/shop/product/bike/'));
        // The convention answers below the localized mount as well
        $this->assertSame('cart', $this->body('/en/shop/cart/'));
        $this->assertSame(404, $this->get('/en/boutique/produit/velo/')->getStatusCode());
    }

    public function testACamelizedSegmentRedirectsToTheCanonicalUrl(): void
    {
        // /shop/Cart/ resolves CartController, but the canonical url is lowercase
        $response = $this->get('/en/shop/Cart/');
        $this->assertSame(307, $response->getStatusCode());
        $this->assertSame('/en/shop/cart/', $response->getHeaderLine('Location'));

        $response = $this->get('/en/shop/Product/');
        $this->assertSame(307, $response->getStatusCode());
        $this->assertSame('/en/shop/product/', $response->getHeaderLine('Location'));

        // The redirect converges: it is not a loop back to the same url
        $this->assertSame(200, $this->get('/en/shop/cart/')->getStatusCode());
    }

    public function testALocalizedModuleRequiresItsLocale(): void
    {
        $response = $this->get('/boutique/produit/velo/');
        $this->assertSame(307, $response->getStatusCode());
        $this->assertSame('/fr/boutique/produit/velo/', $response->getHeaderLine('Location'));
    }

    public function testAClaimCanBeLocalized(): void
    {
        $this->assertSame('news', $this->body('/fr/actualites/'));
        $this->assertSame('news', $this->body('/en/news/'));
        // The module keeps its own segment
        $this->assertSame('blog', $this->body('/fr/blog/'));
    }

    public function testAResolverResolvesDynamicallyAndBindsWhatItFound(): void
    {
        $this->assertSame('page:Company', $this->body('/fr/company/'));
        $this->assertSame('page:Team', $this->body('/fr/company/team/'));
        // The remaining segment is the action of the page
        $this->assertSame('print:Team', $this->body('/fr/company/team/print/'));
        // Unknown pages hand over to the convention, which does not know them either
        $this->assertSame(404, $this->get('/fr/company/nope/')->getStatusCode());
    }

    public function testUrlsFollowTheLocale(): void
    {
        $router = $this->router();
        $this->assertSame('/fr/a-propos/', $router->url('contact'));
        $this->assertSame('/en/about/', $router->url('contact', locale: 'en'));
        $this->assertSame('/fr/a-propos/', $router->url('app:contact'));
        $this->assertSame('/fr/boutique/produit/velo/', $router->url('shop:product', ['slug' => 'velo']));
        $this->assertSame('/en/shop/product/bike/', $router->url('shop:product', ['slug' => 'bike'], 'en'));
        $this->assertSame('/en/news/', $router->url('blog:news', locale: 'en'));
        $this->assertSame('/en/shop/cart/', $router->urlFor([\Shop\Controller\CartController::class, 'index'], locale: 'en'));
    }

    public function testAClaimCannotStealTheSegmentOfAnotherModule(): void
    {
        $shop = new Module(__DIR__ . '/data/apps/routing/modules/Shop');
        $blog = (new Module(__DIR__ . '/data/apps/routing/modules/Blog'))->claim('/shop/deals', static function (Routes $routes): void {});

        $this->expectException(Ex::class);
        $this->expectExceptionMessage("Module 'blog' claims '/shop/deals' inside the segment of module 'shop'");
        new Router([$shop, $blog]);
    }

    public function testTwoModulesCannotClaimTheSamePrefix(): void
    {
        $noop = static function (Routes $routes): void {};
        $shop = (new Module(__DIR__ . '/data/apps/routing/modules/Shop'))->claim('/deals', $noop);
        $blog = (new Module(__DIR__ . '/data/apps/routing/modules/Blog'))->claim('/deals', $noop);

        $this->expectException(Ex::class);
        $this->expectExceptionMessage("Prefix '/deals' is claimed by both 'shop' and 'blog'");
        new Router([$shop, $blog]);
    }

    public function testTheRootBelongsToTheModuleMountedOnSlashWhateverItsNamespace(): void
    {
        $module = (new Module(__DIR__ . '/modules/TestModule'))
            ->namespace('Site')
            ->mount('/')
            ->routes(static function (Routes $routes): void {
                $routes->get('/', [IndexController::class, 'index'])->name('home');
            });
        // The namespace is decoupled from the files: load the handler directly
        // (an App boot would register the module autoloader instead)
        require_once __DIR__ . '/modules/TestModule/src/Controller/IndexController.php';
        $router = new Router([$module]);

        $this->assertSame('test-module', $router->getDefaultModule());

        $route = $router->match(HttpFactory::createRequestFromGlobals()->withUri(new Uri('/')));
        $this->assertSame(IndexController::class, $route->controller);
        $this->assertSame('index', $route->action);

        $this->assertSame('/', $router->url('home'));
    }

    public function testAMinimalRootModuleServesSlash(): void
    {
        // The readme quickstart shape: one config.php mounting '/', one
        // conventional controller, no namespace magic
        $app = App::create(__DIR__ . '/data/apps/root', false)->boot();

        $response = $app->handle(HttpFactory::createRequestFromGlobals()->withUri(new Uri('/')));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Hello', (string) $response->getBody());
    }

    public function testTwoModulesCannotMountTheRoot(): void
    {
        $first = (new Module(__DIR__ . '/modules/TestModule'))->mount('/');
        $second = (new Module(__DIR__ . '/modules/MappedModule'))->mount('/');

        $this->expectException(Ex::class);
        $this->expectExceptionMessage("Module 'mapped-module' mounts '/' but 'test-module' already owns the root");
        new Router([$first, $second]);
    }

    public function testARootMountCannotMixWithSegments(): void
    {
        $module = (new Module(__DIR__ . '/modules/LangModule'))
            ->localized()
            ->mount(['fr' => '/', 'en' => 'lang']);

        $this->expectException(Ex::class);
        $this->expectExceptionMessage("mixes the root mount '/' with url segments");
        new Router([$module], null, ['fr', 'en']);
    }

    public function testAMissingRootSaysWhichModulesExist(): void
    {
        $shop = (new Module(__DIR__ . '/data/apps/routing/modules/Shop'))
            ->localized()
            ->mount(['fr' => 'boutique', 'en' => 'shop']);
        $router = new Router([$shop], null, ['fr', 'en']);

        try {
            $router->match(HttpFactory::createRequestFromGlobals()->withUri(new Uri('/fr/nope/')));
            $this->fail('unmounted path must not resolve without a root module');
        } catch (RouteNotFoundException $e) {
            $this->assertStringContainsString("no module owns the root (mount('/') in a config.php)", $e->getMessage());
            $this->assertStringContainsString("'shop' on /fr/boutique/", $e->getMessage());
        }
    }
}
