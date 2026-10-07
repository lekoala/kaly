<?php

declare(strict_types=1);

namespace Kaly\Tests;

use InvalidArgumentException;
use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Router\TrailingSlash;
use Kaly\Test\TestClient;
use Kaly\Test\TestResponse;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * In-process functional tests: PSR-7 request in, PSR-7 response out, no
 * socket and no browser emulation.
 */
class TestClientTest extends TestCase
{
    private TestClient $client;

    protected function setUp(): void
    {
        $this->client = TestClient::for(App::create(__DIR__)->routing(TrailingSlash::Add, true)->boot());
    }

    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    public function testGetHappyPath(): void
    {
        $this->client->get('/test-module/alias/hello/')->assertStatus(200)->assertBody('alias-hello');
    }

    public function testPost(): void
    {
        $this->client->post('/test-module/alias/save/')->assertStatus(200)->assertBody('alias-saved');
    }

    public function testQueryOptionsFeedTheRequest(): void
    {
        $this->client
            ->get('/test-module/input/search/', ['query' => ['q' => 'hello', 'page' => 2]])
            ->assertStatus(200)
            ->assertBody('hello:2');
    }

    public function testRedirectLocation(): void
    {
        $this->client->get('/test-module/index/redirect/')->assertStatus(307)->assertLocation('/test-module');
    }

    public function testResponseFallsBackToPsr7(): void
    {
        $response = $this->client->get('/test-module/alias/hello/')->response();

        $this->assertInstanceOf(ResponseInterface::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUnknownOptionFails(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown request options: history');

        $this->client->get('/', ['history' => true]);
    }

    public function testASingleBodyOptionIsAllowed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only one of json, form or body may be given');

        $this->client->post('/', ['json' => ['a' => 1], 'form' => ['b' => 2]]);
    }

    public function testAssertJson(): void
    {
        $response = new TestResponse(new Response(200, ['Content-Type' => 'application/json'], '{"hello":"world"}'));

        $response->assertStatus(200)->assertJson(['hello' => 'world']);
    }

    public function testAssertJsonFailsLoudlyOnInvalidJson(): void
    {
        $response = new TestResponse(new Response(200, [], 'nope'));

        $this->expectException(ExpectationFailedException::class);
        $response->assertJson(['hello' => 'world']);
    }
}
