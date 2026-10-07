<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Di\Definitions;
use Kaly\Http\Middleware\FileServer;
use Kaly\Http\Middleware\PreventSensitivePathAccess;
use Kaly\Http\Session\SessionProviderInterface;
use Kaly\Router\TrailingSlash;
use Kaly\Test\MemorySessionProvider;
use Kaly\Test\TestClient;
use Kaly\Tests\Support\TempDir;
use Kaly\Util\Fs;
use PHPUnit\Framework\TestCase;

class FinalScenarioTest extends TestCase
{
    private string $base;
    private App $app;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-final-' . uniqid();
        Fs::ensureDir($this->base . '/modules/Site');
        Fs::ensureDir($this->base . '/public/.well-known/acme-challenge');
        Fs::putFile($this->base . '/public/.well-known/acme-challenge/token', 'challenge-token');
        Fs::ensureDir($this->base . '/modules/Site/templates');
        Fs::putFile($this->base . '/modules/Site/templates/hello.phtml', "<?= \$v->e(\$i18n->translate('global.test')) ?>\n");
        Fs::putFile($this->base . '/modules/Site/config.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            use Kaly\Core\Middleware\RouteLocale;
            use Kaly\Core\Module;
            use Kaly\Di\Definitions;
            use Kaly\I18n\Translator;
            use Kaly\I18n\TranslatorInterface;
            use Kaly\Router\Routes;
            use Kaly\Tests\Mocks\AuthFlowFixture;
            use Kaly\Tests\Mocks\LocaleEchoFixture;
            use Kaly\Tests\Mocks\RouteHandlerFixture;
            use Kaly\Tpl\ViewEngine;
            use Kaly\View\Adapter\KalyTplRenderer;
            use Kaly\View\RendererInterface;
            return static function (Module $module, Definitions $di): void {
                $di->set(RendererInterface::class, new KalyTplRenderer(new ViewEngine(__DIR__ . '/templates')));
                $translator = (new Translator('en'))
                    ->addToCatalog('messages', 'fr', ['global' => ['test' => 'Message de test']])
                    ->addToCatalog('messages', 'nl', ['global' => ['test' => 'Testbericht']])
                    ->addToCatalog('messages', 'en', ['global' => ['test' => 'Test message']]);
                $di->set(TranslatorInterface::class, $translator);
                $module->mount('/')->routes(function (Routes $routes): void {
                    $routes->get('/{locale}/hello', [LocaleEchoFixture::class, 'hello'])->middleware(RouteLocale::class);
                    $routes->get('/{locale}/greet', [LocaleEchoFixture::class, 'greet'])->middleware(RouteLocale::class);
                    $routes->get('/sitemap.xml', RouteHandlerFixture::class);
                    $routes->get('/robots.txt', RouteHandlerFixture::class);
                    $routes->post('/login', [AuthFlowFixture::class, 'login']);
                    $routes->post('/logout', [AuthFlowFixture::class, 'logout']);
                    $routes->get('/me', [AuthFlowFixture::class, 'me']);
                });
            };
            PHP);

        $this->app = App::create($this->base, false)
            ->locales(['fr', 'nl', 'en'])
            ->routing(TrailingSlash::Preserve, false)
            ->configure(static function (Definitions $di): void {
                $di->rebind(SessionProviderInterface::class, new MemorySessionProvider());
            })
            ->boot();
        $this->app->middleware()->incoming(FileServer::class, priority: -100)->incoming(PreventSensitivePathAccess::class);
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->base);
        ErrorHandler::restoreDefaults();
    }

    public function testOneBootServesEveryScenarioWithoutLeakingBetweenCycles(): void
    {
        // Dotted application routes reach their handler, served files win first
        $client = TestClient::for($this->app);
        $client->get('/sitemap.xml')->assertStatus(200)->assertBody('invoked');
        $client->get('/robots.txt')->assertStatus(200);
        $wellKnown = $client->get('/.well-known/acme-challenge/token');
        $wellKnown->assertStatus(200)->assertBody('challenge-token');
        $wellKnown->response()->getBody()->close();

        // FR authenticated, NL anonymous, FR anonymous, NL authenticated
        $fr = TestClient::for($this->app);
        $fr->post('/login');
        $fr->followRedirect()->assertBody('user:42');
        $fr->get('/nl/hello')->assertBody('nl:nl');
        $fr->get('/fr/hello')->assertBody('fr:fr');
        $fr->get('/fr/greet')->assertBody('Message de test');
        $fr->get('/nl/greet')->assertBody('Testbericht');

        $nl = TestClient::for($this->app);
        $nl->get('/me')->assertStatus(401);
        $nl->get('/fr/hello')->assertBody('fr:fr');

        $frAnonymous = TestClient::for($this->app);
        $frAnonymous->get('/me')->assertStatus(401);
        $frAnonymous->get('/nl/hello')->assertBody('nl:nl');

        $nlAuth = TestClient::for($this->app);
        $nlAuth->post('/login');
        $nlAuth->followRedirect()->assertBody('user:42');
        $nlAuth->get('/fr/hello')->assertBody('fr:fr');

        // Sessions never cross clients
        $this->assertNotSame($fr->cookies(), $nl->cookies());
        $this->assertNotSame($fr->cookies(), $nlAuth->cookies());
    }
}
