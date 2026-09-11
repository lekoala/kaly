<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    /**
     * @param array<string,mixed>|null $body
     */
    private function request(string $path, string $method = 'GET', ?array $body = null): \Psr\Http\Message\ResponseInterface
    {
        $app = new App(__DIR__);
        $app->boot();

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri($path))->withMethod($method);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
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

    public function testVerbSuffixedActionIsRestricted(): void
    {
        $response = $this->request('/test-module/index/change-post/', 'GET');
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST', $response->getHeaderLine('Allow'));

        $response = $this->request('/test-module/index/change-post/', 'POST');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('mutation-called', (string) $response->getBody());
    }

    public function testBareActionMapsToSuffixedMethod(): void
    {
        // A bare name resolves to the method-suffixed action
        $response = $this->request('/test-module/index/change/', 'POST');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('mutation-called', (string) $response->getBody());

        // ... but only for the matching verb
        $response = $this->request('/test-module/index/change/', 'GET');
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('POST', $response->getHeaderLine('Allow'));
    }

    public function testZeroSegmentIsPreserved(): void
    {
        $response = $this->request('/test-module/index/typed-int/0/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('0', (string) $response->getBody());
    }

    public function testRequiredPostBodyIsMappedToAnInput(): void
    {
        $response = $this->request('/test-module/index/required-post/', 'POST', ['test' => 'body']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"test"', (string) $response->getBody());
        $this->assertStringContainsString('"body"', (string) $response->getBody());
    }

    public function testRequiredPostWithoutBodyIsABadRequest(): void
    {
        // The route matches, the input cannot be built: 400, not 404
        $response = $this->request('/test-module/index/required-post/', 'POST');
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testMethodSuffixedActionMatchesVerb(): void
    {
        $response = $this->request('/test-module/index/method/', 'GET');
        $this->assertSame('get', (string) $response->getBody());

        $response = $this->request('/test-module/index/method/', 'POST');
        $this->assertSame('post', (string) $response->getBody());
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
