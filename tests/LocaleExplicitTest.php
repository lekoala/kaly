<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\HttpContext;
use Kaly\Core\Middleware\NullHandler;
use Kaly\Core\Middleware\RouteLocale;
use Kaly\Ex;
use Kaly\I18n\LocaleResolver;
use Kaly\Router\Route;
use Kaly\Router\RouteRequest;
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

/**
 * Route::$localeExplicit distinguishes a locale the routing imposes from the
 * application default, so RouteLocale never silently overrides a declared one.
 */
class LocaleExplicitTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    /**
     * @param array<int<0,max>|string,mixed> $params
     */
    private function ctxWithRoute(array $params, ?string $routeLocale, bool $explicit): HttpContext
    {
        $request = new ServerRequest('GET', '/nl/hello');
        $ctx = new HttpContext($request);
        $ctx->bind($request);
        $ctx->useRoute(new Route(
            LocaleEchoFixture::class,
            'hello',
            $params,
            locale: $routeLocale,
            name: 'site:hello',
            localeExplicit: $explicit,
        ));
        $ctx->useLocale('en');

        return $ctx;
    }

    public function testRouteCarriesTheExplicitFlagThroughMiddlewares(): void
    {
        $implicit = new Route(LocaleEchoFixture::class, 'hello', ['nl'], locale: 'en', localeExplicit: false);
        $this->assertFalse($implicit->withMiddlewares([])->localeExplicit);

        $explicit = new Route(LocaleEchoFixture::class, 'hello', ['nl'], locale: 'en');
        $this->assertTrue($explicit->withMiddlewares([])->localeExplicit);
    }

    public function testRouteRequestDefaultsToImplicit(): void
    {
        $request = new RouteRequest(new ServerRequest('GET', '/x'), [], '', 'App', 'en');
        $this->assertFalse($request->route(LocaleEchoFixture::class, 'hello')->localeExplicit);
    }

    public function testRouteRequestSelectingALocaleIsExplicitByDefault(): void
    {
        $request = new RouteRequest(new ServerRequest('GET', '/x'), [], '', 'App', 'en');

        $this->assertTrue($request->withLocale('fr')->localeExplicit);
        $this->assertFalse($request->withLocale('fr', false)->localeExplicit);
    }

    public function testOwnedActionsPreserveTheLocaleExplicitFlag(): void
    {
        $request = new RouteRequest(new ServerRequest('GET', '/x'), [], '', 'App', 'en', localeExplicit: true);
        $this->assertTrue($request->withOwnedActions(['a::b' => true])->localeExplicit);
    }

    public function testExplicitDefaultLocaleContradictsThePlaceholder(): void
    {
        $middleware = new RouteLocale(new LocaleResolver('en', ['en', 'fr', 'nl']));

        $this->expectException(Ex::class);
        $middleware->process($this->ctxWithRoute(['nl'], 'en', true)->request(), new NullHandler());
    }

    public function testImplicitDefaultLocaleLetsThePlaceholderWin(): void
    {
        $middleware = new RouteLocale(new LocaleResolver('en', ['en', 'fr', 'nl']));
        $ctx = $this->ctxWithRoute(['nl'], 'en', false);

        $response = $middleware->process($ctx->request(), new PredefinedResponseHandler(new Response(200)));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('nl', $ctx->locale());
    }

    public function testADeclaredLocaleIsEnforcedThroughTheRouteTable(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-locale-explicit-' . uniqid();
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
                    $routes->get(['en' => '/english/{locale}'], [LocaleEchoFixture::class, 'hello'])
                        ->middleware(RouteLocale::class);
                });
            };
            PHP);
        try {
            $app = App::create($base, false)->locales(['en', 'fr', 'nl'])->routing(TrailingSlash::Preserve, false)->boot();

            $ok = $app->handle(HttpFactory::createRequestFromGlobals()->withUri(new Uri('/english/en')));
            $this->assertSame(200, $ok->getStatusCode());
            $this->assertSame('en:en', (string) $ok->getBody());

            // The declared 'en' variant must not silently run as 'nl'
            $contradiction = $app->handle(HttpFactory::createRequestFromGlobals()->withUri(new Uri('/english/nl')));
            $this->assertSame(500, $contradiction->getStatusCode());
        } finally {
            TempDir::remove($base);
        }
    }
}
