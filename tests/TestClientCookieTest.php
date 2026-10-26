<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Router\TrailingSlash;
use Kaly\Test\TestClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TestClientCookieTest extends TestCase
{
    private CookieProbeMiddleware $probe;
    private TestClient $client;

    protected function setUp(): void
    {
        $this->probe = new CookieProbeMiddleware();
        $app = App::create(__DIR__)->routing(TrailingSlash::Preserve, true);
        $app->middleware()->incoming($this->probe);
        $this->client = TestClient::for($app->boot());
    }

    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    public function testAnonymousRequestsHaveNoCookieHeaderOrParameters(): void
    {
        $this->client->get('/inspect')->assertStatus(200);
        $request = $this->probe->requests[0];

        $this->assertFalse($request->hasHeader('Cookie'));
        $this->assertSame([], $request->getCookieParams());
        $this->assertSame([], $this->client->cookies());
    }

    public function testStoredCookiesReachBothRequestRepresentations(): void
    {
        $this->probe->response = new Response(200, ['Set-Cookie' => ['theme=dark; Path=/', 'language=fr; HttpOnly']]);
        $this->client->get('/seed');
        $this->probe->response = new Response();
        $this->client->get('/inspect');
        $request = $this->probe->requests[1];

        $this->assertSame(['theme' => 'dark', 'language' => 'fr'], $request->getCookieParams());
        $this->assertSame('theme=dark; language=fr', $request->getHeaderLine('Cookie'));
        $this->assertSame(['theme' => 'dark', 'language' => 'fr'], $this->client->cookies());
    }

    public function testExplicitCookiesOverrideStoredValuesForOneRequest(): void
    {
        $this->probe->response = new Response(200, ['Set-Cookie' => ['theme=dark', 'language=fr']]);
        $this->client->get('/seed');
        $this->probe->response = new Response();
        $this->client->get('/inspect', ['cookies' => ['theme' => 'light', 'preview' => '1']]);
        $request = $this->probe->requests[1];

        $this->assertSame(['theme' => 'light', 'language' => 'fr', 'preview' => '1'], $request->getCookieParams());
        $this->assertSame('theme=light; language=fr; preview=1', $request->getHeaderLine('Cookie'));
        $this->assertSame(['theme' => 'dark', 'language' => 'fr'], $this->client->cookies());

        $this->client->get('/inspect');
        $next = $this->probe->requests[2];
        $this->assertSame(['theme' => 'dark', 'language' => 'fr'], $next->getCookieParams());
        $this->assertSame('theme=dark; language=fr', $next->getHeaderLine('Cookie'));
    }

    public function testClearingTheStoreRemovesBothRequestRepresentations(): void
    {
        $this->probe->response = new Response(200, ['Set-Cookie' => 'theme=dark']);
        $this->client->get('/seed');
        $this->probe->response = new Response();
        $this->client->clearCookies();
        $this->client->get('/inspect');
        $request = $this->probe->requests[1];

        $this->assertSame([], $this->client->cookies());
        $this->assertSame([], $request->getCookieParams());
        $this->assertFalse($request->hasHeader('Cookie'));
    }

    /** @return array<string, array{string}> */
    public static function cookieHeaderNames(): array
    {
        return ['canonical' => ['Cookie'], 'lowercase' => ['cookie'], 'mixed case' => ['cOoKiE']];
    }

    #[DataProvider('cookieHeaderNames')]
    public function testExplicitCookieHeaderIsPreservedIndependentlyOfCookieParameters(string $headerName): void
    {
        $this->probe->response = new Response(200, ['Set-Cookie' => 'theme=dark']);
        $this->client->get('/seed');
        $this->probe->response = new Response();
        $this->client->get('/inspect', [
            'headers' => [$headerName => 'manual=raw%3Bvalue'],
            'cookies' => ['preview' => '1'],
        ]);
        $request = $this->probe->requests[1];

        $this->assertSame(['manual=raw%3Bvalue'], $request->getHeader('Cookie'));
        $this->assertSame(['theme' => 'dark', 'preview' => '1'], $request->getCookieParams());
        $this->assertSame(['theme' => 'dark'], $this->client->cookies());
    }

    public function testExplicitCookieValuesAreEncodedOnlyInTheHeader(): void
    {
        $this->client->get('/inspect', ['cookies' => ['note' => 'a b+c;=/%é', 'empty' => '', 'zero' => '0']]);
        $request = $this->probe->requests[0];

        $this->assertSame(['note' => 'a b+c;=/%é', 'empty' => '', 'zero' => '0'], $request->getCookieParams());
        $this->assertSame('note=a+b%2Bc%3B%3D%2F%25%C3%A9; empty=; zero=0', $request->getHeaderLine('Cookie'));
        $this->assertSame([], $this->client->cookies());
    }

    public function testResponseCookieValuesRoundTripWithoutLosingTheirEncoding(): void
    {
        $this->probe->response = new Response(200, [
            'Set-Cookie' => ['note=a+b%2Bc%3B%3D%2F%25%C3%A9; Path=/', 'pair=abc=def; HttpOnly', 'zero=0'],
        ]);
        $this->client->get('/seed');
        $this->assertSame(['note' => 'a b+c;=/%é', 'pair' => 'abc=def', 'zero' => '0'], $this->client->cookies());
        $this->probe->response = new Response();
        $this->client->get('/inspect');
        $request = $this->probe->requests[1];

        $this->assertSame(['note' => 'a b+c;=/%é', 'pair' => 'abc=def', 'zero' => '0'], $request->getCookieParams());
        $this->assertSame('note=a+b%2Bc%3B%3D%2F%25%C3%A9; pair=abc%3Ddef; zero=0', $request->getHeaderLine('Cookie'));
    }

    public function testDeletingACookieStillProcessesTheFollowingResponseCookies(): void
    {
        $this->probe->response = new Response(200, ['Set-Cookie' => ['theme=dark', 'language=fr']]);
        $this->client->get('/seed');
        $this->probe->response = new Response(200, ['Set-Cookie' => ['theme=; Max-Age=0', 'language=nl', 'preview=1']]);
        $this->client->get('/update');
        $this->assertSame(['language' => 'nl', 'preview' => '1'], $this->client->cookies());
        $this->probe->response = new Response();
        $this->client->get('/inspect');
        $request = $this->probe->requests[2];

        $this->assertSame(['language' => 'nl', 'preview' => '1'], $request->getCookieParams());
        $this->assertSame('language=nl; preview=1', $request->getHeaderLine('Cookie'));
    }

    public function testRedirectRebuildsTheCookieHeaderFromTheUpdatedStore(): void
    {
        $this->probe->response = new Response(303, ['Location' => '/inspect', 'Set-Cookie' => 'theme=light']);
        $this->client->post('/login', ['headers' => ['cOoKiE' => 'stale=1', 'X-Trace' => 'trace', 'X-Test' => 'test']]);
        $this->probe->response = new Response();
        $this->client->followRedirect()->assertStatus(200);
        $request = $this->probe->requests[1];

        $this->assertSame('GET', $request->getMethod());
        $this->assertSame(['theme' => 'light'], $request->getCookieParams());
        $this->assertSame(['theme=light'], $request->getHeader('Cookie'));
        $this->assertSame('trace', $request->getHeaderLine('X-Trace'));
        $this->assertSame('test', $request->getHeaderLine('X-Test'));
    }

    public function testMalformedResponseCookiesDoNotHideFollowingValidCookies(): void
    {
        $this->probe->response = new Response(200, ['Set-Cookie' => ['malformed', '=invalid', ' theme = dark ; Path=/']]);
        $this->client->get('/seed');
        $this->assertSame(['theme' => 'dark'], $this->client->cookies());
        $this->probe->response = new Response();
        $this->client->get('/inspect');
        $request = $this->probe->requests[1];

        $this->assertSame(['theme' => 'dark'], $request->getCookieParams());
        $this->assertSame('theme=dark', $request->getHeaderLine('Cookie'));
    }
}

final class CookieProbeMiddleware implements MiddlewareInterface
{
    /** @var list<ServerRequestInterface> */
    public array $requests = [];

    public ResponseInterface $response;

    public function __construct()
    {
        $this->response = new Response();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->requests[] = $request;
        return $this->response;
    }
}
