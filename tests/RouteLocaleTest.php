<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\HttpContext;
use Kaly\Core\Middleware\NullHandler;
use Kaly\Core\Middleware\RouteLocale;
use Kaly\Ex;
use Kaly\Http\Exception\NotFoundException;
use Kaly\I18n\LocaleResolver;
use Kaly\Router\Route;
use Kaly\Router\TrailingSlash;
use Kaly\Test\PredefinedResponseHandler;
use Kaly\Tests\Mocks\LocaleEchoFixture;
use Kaly\Tests\Support\HttpFactory;
use Kaly\Tests\Support\TempDir;
use Kaly\Util\Fs;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;

class RouteLocaleTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    /**
     * @param array<int<0,max>|string,mixed> $params
     */
    private function ctxWithRoute(array $params, ?string $routeLocale = null): HttpContext
    {
        $request = new ServerRequest('GET', '/nl/hello');
        $ctx = new HttpContext($request);
        $ctx->bind($request);
        $ctx->useRoute(new Route(LocaleEchoFixture::class, 'hello', $params, locale: $routeLocale, name: 'site:hello'));
        $ctx->useLocale('en');
        return $ctx;
    }

    public function testSupportedParamBecomesTheRequestLocale(): void
    {
        $middleware = new RouteLocale(new LocaleResolver('en', ['en', 'fr', 'nl']));
        $ctx = $this->ctxWithRoute(['nl']);

        $response = $middleware->process($ctx->request(), new PredefinedResponseHandler(new Response(200)));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('nl', $ctx->locale());
    }

    public function testUnsupportedParamIsA404WithoutFallback(): void
    {
        $middleware = new RouteLocale(new LocaleResolver('en', ['en', 'fr', 'nl']));

        $this->expectException(NotFoundException::class);
        $middleware->process($this->ctxWithRoute(['de'])->request(), new NullHandler());
    }

    public function testMissingParamIsADeveloperError(): void
    {
        $middleware = new RouteLocale(new LocaleResolver('en', ['en', 'fr', 'nl']));

        $this->expectException(Ex::class);
        $middleware->process($this->ctxWithRoute([])->request(), new NullHandler());
    }

    public function testContradictoryRouteLocaleIsADeveloperError(): void
    {
        $middleware = new RouteLocale(new LocaleResolver('en', ['en', 'fr', 'nl']));

        $this->expectException(Ex::class);
        $middleware->process($this->ctxWithRoute(['nl'], 'fr')->request(), new NullHandler());
    }

    public function testLocaleIsVisibleInTheController(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-route-locale-' . uniqid();
        Fs::ensureDir($base . '/modules/LocaleSite');
        Fs::putFile($base . '/modules/LocaleSite/config.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            use Kaly\Core\Middleware\RouteLocale;
            use Kaly\Core\Module;
            use Kaly\Router\Routes;
            use Kaly\Tests\Mocks\LocaleEchoFixture;
            return static function (Module $module): void {
                $module->mount('/')->routes(function (Routes $routes): void {
                    $routes->get('/{locale}/hello', [LocaleEchoFixture::class, 'hello'])->middleware(RouteLocale::class);
                });
            };
            PHP);
        try {
            $app = App::create($base, false)->locales(['en', 'fr', 'nl'])->routing(TrailingSlash::Preserve, false)->boot();

            $nl = $app->handle(HttpFactory::createRequestFromGlobals()->withUri(new Uri('/nl/hello')));
            $this->assertSame(200, $nl->getStatusCode());
            $this->assertSame('nl:nl', (string) $nl->getBody());

            $fr = $app->handle(HttpFactory::createRequestFromGlobals()->withUri(new Uri('/fr/hello')));
            $this->assertSame('fr:fr', (string) $fr->getBody());

            $unknown = $app->handle(HttpFactory::createRequestFromGlobals()->withUri(new Uri('/de/hello')));
            $this->assertSame(404, $unknown->getStatusCode());
        } finally {
            TempDir::remove($base);
        }
    }

    public function testSequentialRequestsStayIsolated(): void
    {
        $middleware = new RouteLocale(new LocaleResolver('en', ['en', 'fr', 'nl']));

        $first = $this->ctxWithRoute(['fr']);
        $middleware->process($first->request(), new PredefinedResponseHandler(new Response(200)));

        $second = $this->ctxWithRoute(['nl']);
        $middleware->process($second->request(), new PredefinedResponseHandler(new Response(200)));

        $this->assertSame('fr', $first->locale());
        $this->assertSame('nl', $second->locale());
    }
}
