<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Router\ConventionResolver;
use Kaly\Router\Route;
use Kaly\Router\RouterInterface;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use TestModule\Controller\DemoController;
use TestModule\Controller\IndexController;

class RouterTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    /**
     * @param array<string,mixed>|null $body
     */
    private function request(string $path, string $method = 'GET', ?array $body = null): ResponseInterface
    {
        $app = (new App(__DIR__))->routing(TrailingSlash::Add, true);
        $app->boot();

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri($path))->withMethod($method);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }
        return $app->handle($request);
    }

    private function match(string $path): Route
    {
        $app = (new App(__DIR__))->routing(TrailingSlash::Add, true);
        $app->boot();

        return $app
            ->container()
            ->get(RouterInterface::class)
            ->match(HttpFactory::createRequestFromGlobals()->withUri(new Uri($path)));
    }

    public function testEveryVariadicSegmentIsCoerced(): void
    {
        $route = $this->match('/test-module/index/numbers/1/2/');

        $this->assertSame([1, 2], $route->params);
    }

    public function testIntParameterIsCoerced(): void
    {
        $response = $this->request('/test-module/index/typed-int/123/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('123', (string) $response->getBody());
    }

    public function testStaticActionIsNotRoutable(): void
    {
        // Same admissibility rule as declared routes: public AND non-static
        $this->assertSame(404, $this->request('/test-module/index/staticaction/')->getStatusCode());
        $this->assertSame(404, $this->request('/test-module/index/isinvalid/')->getStatusCode());
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

    public function testTheVerbSuffixIsNeverPartOfTheUrl(): void
    {
        // changePost() has one url, /change/: no alias for any method. The
        // segment is left to index(), which takes no parameter: no method
        // can match, so it is a 404 rather than a 405
        foreach (['GET', 'POST'] as $method) {
            $this->assertSame(404, $this->request('/test-module/index/change-post/', $method)->getStatusCode(), $method);
        }
        $this->assertSame('/index/change', (new ConventionResolver())->path(IndexController::class, 'changePost'));
    }

    public function testANameEndingWithAVerbTakesASuffix(): void
    {
        // lastPostGet() is the GET of /last-post/, its Post is part of the name
        $this->assertSame('last post', (string) $this->request('/test-module/demo/last-post/')->getBody());
        $this->assertSame(200, $this->request('/test-module/demo/last-post/', 'HEAD')->getStatusCode());
        $response = $this->request('/test-module/demo/last-post/', 'POST');
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame(['GET', 'HEAD'], $this->allowed($response));
        $this->assertSame('/demo/last-post', (new ConventionResolver())->path(DemoController::class, 'lastPostGet'));
    }

    public function testA405RequiresAVariantThatTakesTheSegments(): void
    {
        // index(string $param) takes one segment: other methods are a 405
        $response = $this->request('/test-module/demo/anything/', 'PUT');
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame(['GET', 'HEAD'], $this->allowed($response));

        // ... but not two, and indexPost() takes none: a 404 for every method
        foreach (['GET', 'PUT'] as $method) {
            $this->assertSame(404, $this->request('/test-module/demo/any/thing/', $method)->getStatusCode(), $method);
        }
        // A named action works the same way: foo() takes no segment
        $this->assertSame(404, $this->request('/test-module/index/foo/extra/', 'POST')->getStatusCode());
    }

    public function testACanonicalRedirectKeepsTheMethodAndTheSegments(): void
    {
        // Redirected during routing: the action never ran on the first request
        $response = $this->request('/test-module/demo/moveItem/42/', 'POST');
        $this->assertSame(307, $response->getStatusCode());
        $this->assertSame('/test-module/demo/move-item/42/', $response->getHeaderLine('Location'));
        $this->assertStringNotContainsString('moved', (string) $response->getBody());

        $this->assertSame('moved 42', (string) $this->request('/test-module/demo/move-item/42/', 'POST')->getBody());
    }

    public function testHeadFallsBackToTheGetAction(): void
    {
        $this->assertSame(200, $this->request('/test-module/demo/method/', 'HEAD')->getStatusCode());

        $response = $this->request('/test-module/demo/method/', 'PUT');
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame(['GET', 'HEAD', 'POST'], $this->allowed($response));
    }

    public function testActionsHaveOneSpelling(): void
    {
        $response = $this->request('/test-module/index/typedInt/3/');
        $this->assertSame(307, $response->getStatusCode());
        $this->assertSame('/test-module/index/typed-int/3/', $response->getHeaderLine('Location'));
        $this->assertSame('/index/typed-int/3', (new ConventionResolver())->path(IndexController::class, 'typedInt', [3]));

        // A segment that is no action is a parameter, never respelled
        $this->assertSame('hello Mixed-Case', (string) $this->request('/test-module/demo/Mixed-Case/')->getBody());
    }

    public function testABareNameAndItsGetVariantCollide(): void
    {
        // Both would answer GET: the application code is invalid
        $this->assertSame(500, $this->request('/test-module/collision/show/')->getStatusCode());
        $this->assertSame(500, $this->request('/test-module/collision/')->getStatusCode());
    }

    public function testInheritedPublicMethodsAreActions(): void
    {
        // Public is reachable wherever it is declared, protected never is
        $this->assertSame('listed items', (string) $this->request('/test-module/inherited/list/')->getBody());
        foreach (['label', 'ctx', 'redirect-to-route'] as $helper) {
            $this->assertSame(404, $this->request("/test-module/inherited/{$helper}/")->getStatusCode(), $helper);
        }
        // An abstract parent is never a controller
        $this->assertSame(404, $this->request('/test-module/abstract-listing/list/')->getStatusCode());
    }

    /**
     * @return list<string>
     */
    private function allowed(ResponseInterface $response): array
    {
        $methods = explode(', ', $response->getHeaderLine('Allow'));
        sort($methods);
        return $methods;
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

    public function testBareActionOnlyAnswersSafeMethods(): void
    {
        // A write names its verb: a cross site GET can never reach it, and a
        // bare name never runs for a write
        $this->assertSame(200, $this->request('/test-module/index/foo/', 'GET')->getStatusCode());
        $this->assertSame(200, $this->request('/test-module/index/foo/', 'HEAD')->getStatusCode());
        foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
            $response = $this->request('/test-module/index/foo/', $method);
            $this->assertSame(405, $response->getStatusCode(), $method);
            $this->assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
        }

        // The module index is a bare action too
        $response = $this->request('/test-module/', 'POST');
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame('GET, HEAD', $response->getHeaderLine('Allow'));
    }

    public function testDefaultActionMapsToSuffixedMethod(): void
    {
        $this->assertSame('hello demo', (string) $this->request('/test-module/demo/')->getBody());
        $this->assertSame(200, $this->request('/test-module/demo/', 'HEAD')->getStatusCode());
        $this->assertSame('demo posted', (string) $this->request('/test-module/demo/', 'POST')->getBody());
        $response = $this->request('/test-module/demo/', 'PUT');
        $this->assertSame(405, $response->getStatusCode());
        $this->assertSame(['GET', 'HEAD', 'POST'], $this->allowed($response));
        $this->assertSame('/demo', (new ConventionResolver())->path(DemoController::class, 'indexPost'));
    }

    public function testZeroSegmentIsPreserved(): void
    {
        $response = $this->request('/test-module/index/typed-int/0/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('0', (string) $response->getBody());
    }

    public function testRequiredPostBodyIsMappedToAnInput(): void
    {
        $response = $this->request('/test-module/index/required/', 'POST', ['test' => 'body']);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"test"', (string) $response->getBody());
        $this->assertStringContainsString('"body"', (string) $response->getBody());
    }

    public function testRequiredPostWithoutBodyIsABadRequest(): void
    {
        // The route matches, the input cannot be built: 400, not 404
        $response = $this->request('/test-module/index/required/', 'POST');
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
