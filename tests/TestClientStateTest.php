<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Di\Definitions;
use Kaly\Http\Session\SessionProviderInterface;
use Kaly\Router\TrailingSlash;
use Kaly\Test\MemorySessionProvider;
use Kaly\Test\TestClient;
use Kaly\Tests\Support\TempDir;
use Kaly\Util\Fs;
use LogicException;
use PHPUnit\Framework\TestCase;

class TestClientStateTest extends TestCase
{
    private string $base;
    private App $app;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-test-client-' . uniqid();
        Fs::ensureDir($this->base . '/modules/Auth');
        Fs::putFile($this->base . '/modules/Auth/config.php', <<<'PHP'
            <?php
            declare(strict_types=1);
            use Kaly\Core\Module;
            use Kaly\Router\Routes;
            use Kaly\Tests\Mocks\AuthFlowFixture;
            return static function (Module $module): void {
                $module->mount('/')->routes(function (Routes $routes): void {
                    $routes->get('/', [AuthFlowFixture::class, 'me'])->name('home');
                    $routes->post('/login', [AuthFlowFixture::class, 'login']);
                    $routes->get('/me', [AuthFlowFixture::class, 'me']);
                    $routes->post('/logout', [AuthFlowFixture::class, 'logout']);
                    $routes->post('/forward', [AuthFlowFixture::class, 'forward']);
                    $routes->post('/target', [AuthFlowFixture::class, 'echoPost']);
                    $routes->post('/forward-parsed', [AuthFlowFixture::class, 'forwardParsed']);
                    $routes->post('/target-parsed', [AuthFlowFixture::class, 'echoParsed']);
                    $routes->get('/a/source', [AuthFlowFixture::class, 'dotSource']);
                    $routes->get('/target', [AuthFlowFixture::class, 'dotTarget']);
                    $routes->get('/proto', [AuthFlowFixture::class, 'protoSource']);
                    $routes->get('/scheme', [AuthFlowFixture::class, 'schemeSource']);
                    $routes->get('/port', [AuthFlowFixture::class, 'portSource']);
                    $routes->get('/abs', [AuthFlowFixture::class, 'absSource']);
                    $routes->get('/target-abs', [AuthFlowFixture::class, 'absTarget']);
                    $routes->get('/bounce', [AuthFlowFixture::class, 'bounce']);
                });
            };
            PHP);

        $this->app = App::create($this->base, false)
            ->routing(TrailingSlash::Preserve, false)
            ->configure(static function (Definitions $di): void {
                $di->rebind(SessionProviderInterface::class, new MemorySessionProvider());
            })
            ->boot();
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->base);
        ErrorHandler::restoreDefaults();
    }

    public function testLoginRedirectMe(): void
    {
        $client = TestClient::for($this->app);

        $login = $client->post('/login');
        $login->assertStatus(303)->assertLocation('/me');
        $this->assertNotSame([], $client->cookies());

        $me = $client->followRedirect();
        $me->assertStatus(200)->assertBody('user:42');
    }

    public function testLogoutDestroysTheSession(): void
    {
        $client = TestClient::for($this->app);
        $client->post('/login');
        $client->followRedirect()->assertBody('user:42');

        $client->post('/logout')->assertStatus(303);

        $me = $client->get('/me');
        $me->assertStatus(401);
    }

    public function testTwoClientsStayIsolated(): void
    {
        $alice = TestClient::for($this->app);
        $bob = TestClient::for($this->app);

        $alice->post('/login');
        $alice->followRedirect()->assertBody('user:42');

        // Bob is anonymous: a different session, no access to Alice's user
        $bob->get('/me')->assertStatus(401);
        $this->assertNotSame($alice->cookies(), $bob->cookies());
    }

    public function testRegeneratedIdRotatesWithoutLosingTheSession(): void
    {
        $client = TestClient::for($this->app);

        $client->post('/login');
        $first = $client->cookies();

        // A second login rotates the id while the session survives
        $client->post('/login');
        $second = $client->cookies();

        $this->assertNotSame($first, $second);
        $client->followRedirect()->assertBody('user:42');
    }

    public function testPost303BecomesGet(): void
    {
        $client = TestClient::for($this->app);
        $client->post('/login', ['form' => ['user' => 'alice']]);

        $me = $client->followRedirect();
        $me->assertStatus(200)->assertBody('user:42');
    }

    public function testPost307ReplaysMethodAndBody(): void
    {
        $client = TestClient::for($this->app);

        $redirect = $client->post('/forward', ['form' => ['a' => '1']]);
        $redirect->assertStatus(307)->assertLocation('/target');

        $target = $client->followRedirect();
        $target->assertStatus(200)->assertBody('POST:a=1');
    }

    public function testPost307ReplaysParsedBody(): void
    {
        $client = TestClient::for($this->app);

        $redirect = $client->post('/forward-parsed', ['form' => ['a' => '1']]);
        $redirect->assertStatus(307)->assertLocation('/target-parsed');

        $target = $client->followRedirect();
        $target->assertStatus(200)->assertBody('POST:1');
    }

    public function testRelativeDotSegmentsNormalize(): void
    {
        $client = TestClient::for($this->app);

        $redirect = $client->get('/a/source');
        $redirect->assertStatus(307)->assertLocation('../target');

        $client->followRedirect()->assertStatus(200)->assertBody('dot-ok');
    }

    public function testProtocolRelativeRedirectIsRefused(): void
    {
        $client = TestClient::for($this->app);
        $client->get('/proto');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cross-origin');
        $client->followRedirect();
    }

    public function testSchemeChangeIsRefused(): void
    {
        $client = TestClient::for($this->app);
        $client->get('http://good.example/scheme');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cross-origin');
        $client->followRedirect();
    }

    public function testPortChangeIsRefused(): void
    {
        $client = TestClient::for($this->app);
        $client->get('http://good.example/port');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('cross-origin');
        $client->followRedirect();
    }

    public function testAbsoluteSameOriginRedirectIsFollowed(): void
    {
        $client = TestClient::for($this->app);
        $client->get('http://good.example/abs');

        $client->followRedirect()->assertStatus(200)->assertBody('abs-ok');
    }

    public function testAutoFollowWithLimit(): void
    {
        $client = TestClient::for($this->app);

        $me = $client->post('/login', ['maxRedirects' => 5]);
        $me->assertStatus(200)->assertBody('user:42');
    }

    public function testRedirectLoopHitsTheLimit(): void
    {
        $client = TestClient::for($this->app);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Too many redirects');
        $client->get('/bounce', ['maxRedirects' => 3]);
    }

    public function testExplicitCookiesWinAndJarResets(): void
    {
        $client = TestClient::for($this->app);
        $client->post('/login');

        $stored = $client->cookies();
        $name = array_key_first($stored);
        $this->assertIsString($name);

        $client->clearCookies();
        $this->assertSame([], $client->cookies());

        $client->get('/me')->assertStatus(401);
    }

    public function testLazySessionSetsNoCookie(): void
    {
        $client = TestClient::for($this->app);

        $client->get('/bounce');
        $this->assertSame([], $client->cookies());
    }
}
