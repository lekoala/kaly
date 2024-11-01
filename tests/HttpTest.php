<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http;
use Kaly\Http\HttpFactory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Kaly\Http\ServerRequest;
use Nyholm\Psr7\ServerRequest as BaseServerRequest;
use Kaly\Http\ResponseEmitter;

class HttpTest extends TestCase
{
    public static array $mockResponse = [];

    public function testParseLanguage(): void
    {
        $baseRequest = new BaseServerRequest("GET", "/");
        $request = new ServerRequest($baseRequest);
        $request = $request->withHeader('Accept-Language', 'en-US,en;q=0.9,fr;q=0.8');

        $result = $request->parseAcceptedLanguages();
        $this->assertArrayHasKey("en-US", $result);
        $this->assertArrayHasKey("en", $result);
        $this->assertArrayHasKey("fr", $result);
        $this->assertEquals(0.8, $result["fr"]);

        $preferred =  $request->getPreferredLanguage();
        $this->assertEquals("en-US", $preferred);

        $preferred =  $request->getPreferredLanguage(['en', 'fr']);
        $this->assertEquals("en", $preferred);
    }

    public function testParseAccept(): void
    {
        $baseRequest = new BaseServerRequest("GET", "/");
        $request = new ServerRequest($baseRequest);

        $v = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.9';
        $request = $request->withHeader('Accept', $v);
        $result = $request->parseAcceptHeader();
        $this->assertContains("text/html", $result);
        $this->assertContains("image/webp", $result);

        $preferred = $request->getPreferredContentType();
        $this->assertEquals("text/html", $preferred);
    }

    public function testCreateRequest(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
        $this->assertInstanceOf(ServerRequestInterface::class, $request);
        $this->assertEquals('GET', $request->getMethod());
    }

    public function testCreateResponse(): void
    {
        $response = HttpFactory::createResponse();
        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    public function testResponseFactory(): void
    {
        $factory = HttpFactory::get();
        $this->assertInstanceOf(ResponseInterface::class, $factory->createResponse());
    }

    public function testSendResponse(): void
    {
        $headers = [];
        $code = 200;
        $body = "Test body";
        $testResponse = new Response($code, $headers, $body);

        $this->expectOutputString($body);

        $emitter = new ResponseEmitter();
        $emitter->emit($testResponse);
    }

    public function testChunkedSendResponse(): void
    {
        $headers = [];
        $code = 200;
        $body = "Test body";
        $testResponse = new Response($code, $headers, $body);

        // It should rewind properly
        $testResponse->getBody()->seek(2);

        $this->expectOutputString($body);

        $emitter = new ResponseEmitter(1);
        $emitter->emit($testResponse);
    }

    public function testContentRangeSendResponse(): void
    {
        $headers = [
            'Content-Range' => 'bytes 0-3/8'
        ];
        $code = 200;
        $body = "Test body";
        $testResponse = new Response($code, $headers, $body);

        $this->expectOutputString("Test");

        $emitter = new ResponseEmitter();
        $emitter->emit($testResponse);
    }
}
