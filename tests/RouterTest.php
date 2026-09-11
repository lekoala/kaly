<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Http\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    private function request(string $path): \Psr\Http\Message\ResponseInterface
    {
        $app = new App(__DIR__);
        $app->boot();

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri($path));
        return $app->handle($request);
    }

    public function testIntParameterIsCoerced(): void
    {
        $response = $this->request('/test-module/index/typed-int/123/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('123', (string) $response->getBody());
    }

    public function testInvalidIntParameterIsNotFound(): void
    {
        $response = $this->request('/test-module/index/typed-int/abc/');
        $this->assertSame(404, $response->getStatusCode());

        $response = $this->request('/test-module/index/typed-int/12.5/');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testFloatParameterIsCoerced(): void
    {
        $response = $this->request('/test-module/index/typed-float/1.5/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('1.5', (string) $response->getBody());
    }

    public function testInvalidFloatParameterIsNotFound(): void
    {
        $response = $this->request('/test-module/index/typed-float/abc/');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testBoolParameterIsCoerced(): void
    {
        $response = $this->request('/test-module/index/typed-bool/true/');
        $this->assertSame('true', (string) $response->getBody());

        $response = $this->request('/test-module/index/typed-bool/false/');
        $this->assertSame('false', (string) $response->getBody());

        $response = $this->request('/test-module/index/typed-bool/1/');
        $this->assertSame('true', (string) $response->getBody());

        // "false" must not be truthy, and an integer must not silently become true
        $response = $this->request('/test-module/index/typed-bool/2/');
        $this->assertSame(404, $response->getStatusCode());
    }
}
