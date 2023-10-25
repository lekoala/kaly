<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class HttpTest extends TestCase
{
    public static array $mockResponse = [];

    public function testParseLanguage()
    {
        /** @var ServerRequestInterface $request  */
        $request = new ServerRequest("GET", "/");
        $request = $request->withHeader('Accept-Language', 'en-US,en;q=0.9,fr;q=0.8');

        $result = Http::parseAcceptedLanguages($request);
        $this->assertArrayHasKey("en-US", $result);
        $this->assertArrayHasKey("en", $result);
        $this->assertArrayHasKey("fr", $result);
        $this->assertEquals(0.8, $result["fr"]);

        $preferred = Http::getPreferredLanguage($request);
        $this->assertEquals("en-US", $preferred);

        $preferred = Http::getPreferredLanguage($request, ['en', 'fr']);
        $this->assertEquals("en", $preferred);
    }

    public function testParseAccept()
    {
        /** @var ServerRequestInterface $request  */
        $request = new ServerRequest("GET", "/");
        $v = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.9';
        $request = $request->withHeader('Accept', $v);
        $result = Http::parseAcceptHeader($request);
        $this->assertContains("text/html", $result);
        $this->assertContains("image/webp", $result);

        $preferred = Http::getPreferredContentType($request);
        $this->assertEquals("text/html", $preferred);
    }

    public function testCreateRequest()
    {
        $request = Http::createRequestFromGlobals();
        $this->assertInstanceOf(ServerRequestInterface::class, $request);
        $this->assertEquals('GET', $request->getMethod());
    }

    public function testResolveResponseClass()
    {
        $this->assertNotEmpty(Http::resolveResponseClass());
    }

    public function testCreateResponse()
    {
        $response = Http::respond();
        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    public function testResponseFactory()
    {
        $factory = new Http();
        $this->assertInstanceOf(ResponseInterface::class, $factory->createResponse());
    }

    public function testSendResponse()
    {
        $headers = [];
        $code = 200;
        $body = "Test body";
        $testResponse = new Response($code, $headers, $body);

        $this->expectOutputString($body);
        Http::sendResponse($testResponse);
    }

    public function testChunkedSendResponse()
    {
        $headers = [];
        $code = 200;
        $body = "Test body";
        $testResponse = new Response($code, $headers, $body);

        // It should rewind properly
        $testResponse->getBody()->seek(2);

        $this->expectOutputString($body);
        Http::sendResponse($testResponse, 1);
    }

    public function testContentRangeSendResponse()
    {
        $headers = [
            'Content-Range' => 'bytes 0-3/8'
        ];
        $code = 200;
        $body = "Test body";
        $testResponse = new Response($code, $headers, $body);

        $this->expectOutputString("Test");
        Http::sendResponse($testResponse, 1);
    }
}
