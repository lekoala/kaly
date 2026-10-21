<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Mocks\ImposeLocaleMiddleware;
use Kaly\Tests\Support\HttpFactory;
use Kaly\Tests\Support\TempDir;
use Kaly\Util\Fs;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The router always carries a locale, the application default included.
 * Routing only imposes it on the request when the routing itself chose it.
 */
class RoutingLocaleTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-routing-locale-' . uniqid();
        Fs::ensureDir($this->base . '/modules/LocaleSite');
        Fs::putFile($this->base . '/modules/LocaleSite/config.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            use Kaly\Core\Module;
            use Kaly\Router\Routes;
            use Kaly\Tests\Mocks\LocaleEchoFixture;
            return static function (Module $module): void {
                $module->mount('/')->withoutConventionRouting()->routes(function (Routes $routes): void {
                    $routes->get('/hello', [LocaleEchoFixture::class, 'current']);
                    $routes->get(['fr' => '/bonjour', 'en' => '/welcome'], [LocaleEchoFixture::class, 'current']);
                });
            };
            PHP);
    }

    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
        TempDir::remove($this->base);
    }

    private function app(?string $imposed = null): App
    {
        $app = App::create($this->base, false)->locales(['fr', 'en', 'nl'])->routing(TrailingSlash::Preserve, false);
        if ($imposed !== null) {
            $app->middleware()->incoming(new ImposeLocaleMiddleware($imposed));
        }

        return $app->boot();
    }

    private function get(App $app, string $path, string $acceptLanguage): ResponseInterface
    {
        return $app->handle(
            HttpFactory::createRequestFromGlobals()->withUri(new Uri($path))->withHeader('Accept-Language', $acceptLanguage),
        );
    }

    public function testTheDefaultRouteLocaleDoesNotBlockNegotiation(): void
    {
        $response = $this->get($this->app(), '/hello', 'en');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('en', (string) $response->getBody());
    }

    public function testAnIncomingMiddlewareWinsOverNegotiation(): void
    {
        $response = $this->get($this->app('nl'), '/hello', 'en');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('nl', (string) $response->getBody());
    }

    public function testAnExplicitRouteLocaleWinsOverTheMiddleware(): void
    {
        $response = $this->get($this->app('nl'), '/bonjour', 'en');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('fr', (string) $response->getBody());
    }

    public function testAnExplicitRouteLocaleWinsOverNegotiation(): void
    {
        $response = $this->get($this->app(), '/welcome', 'fr');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('en', (string) $response->getBody());
    }

    public function testWithNoPreferenceTheDefaultLocaleApplies(): void
    {
        $response = $this->get($this->app(), '/hello', '');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('fr', (string) $response->getBody());
    }
}
