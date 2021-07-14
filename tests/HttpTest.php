<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http;
use Kaly\ResponseFactory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class HttpTest extends TestCase
{
    public static array $mockResponse = [];

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
        $response = Http::createResponse();
        $this->assertInstanceOf(ResponseInterface::class, $response);
    }

    public function testResponseFactory()
    {
        $factory = new ResponseFactory();
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
