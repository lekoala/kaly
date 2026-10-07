<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\HttpContext;
use Kaly\Router\Route;
use Kaly\Router\Router;
use Kaly\Router\Routes;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Mocks\RouteHandlerFixture;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Urls generated in the request context: HttpContext::url()/urlFor() and the
 * $url render variable follow the locale of the request, while Router::url()
 * keeps its explicit locale for uses outside a request.
 */
class ContextualUrlsTest extends TestCase
{
    private function router(): Router
    {
        $module = (new \Kaly\Core\Module(__DIR__ . '/modules/MappedModule'))
            ->mount(['fr' => 'boutique', 'en' => 'shop'])
            ->localized()
            ->withoutConventionRouting()
            ->routes(function (Routes $routes): void {
                $routes
                    ->get(['fr' => '/article/{id}', 'en' => '/post/{id}'], [RouteHandlerFixture::class, 'show'])
                    ->where('id', '\d+')
                    ->name('item');
            });
        return new Router([$module], null, ['fr', 'en'], TrailingSlash::Add, true);
    }

    private function ctx(string $locale): HttpContext
    {
        $router = $this->router();
        $ctx = new HttpContext(HttpFactory::createRequestFromGlobals()->withUri(new Uri('/')));
        $ctx->useRouter($router);
        $ctx->useLocale($locale);
        return $ctx;
    }

    public function testRouteIsImmutable(): void
    {
        $reflection = new ReflectionClass(Route::class);
        $this->assertTrue($reflection->isReadOnly());
        foreach (['definition', 'namespace', 'segments'] as $removed) {
            $this->assertFalse($reflection->hasProperty($removed), "Route::\${$removed} must be gone");
        }
        $this->assertFalse($reflection->hasMethod('toArray'), 'Route::toArray() must be gone');
    }

    public function testContextUrlsFollowTheRequestLocale(): void
    {
        $this->assertSame('/en/shop/post/7/', $this->ctx('en')->url('mapped-module:item', ['id' => 7]));
        $this->assertSame('/fr/boutique/article/7/', $this->ctx('fr')->url('mapped-module:item', ['id' => 7]));
    }

    public function testRouterUrlsKeepTheirExplicitLocaleOutsideARequest(): void
    {
        $router = $this->router();
        $this->assertSame('/fr/boutique/article/7/', $router->url('mapped-module:item', ['id' => 7]));
        $this->assertSame('/en/shop/post/7/', $router->url('mapped-module:item', ['id' => 7], 'en'));
    }
}
