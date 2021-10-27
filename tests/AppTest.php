<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\App;
use Kaly\Http;
use Nyholm\Psr7\Uri;
use Kaly\ClassRouter;
use Kaly\Interfaces\RouterInterface;
use Kaly\Tests\Mocks\TestApp;
use Kaly\Tests\Mocks\TestMiddleware;
use PHPUnit\Framework\TestCase;
use TestModule\Controller\DemoController;
use TestModule\Controller\IndexController;

class AppTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV[App::ENV_DEBUG] = true;
    }

    public function testAppExtension()
    {
        $app = new TestApp(__DIR__);
        $app->boot();
        $di = $app->getDi();

        $this->assertTrue($di->has(App::class));
        $this->assertTrue($di->has(TestApp::class));
    }

    public function testLocaleDetection()
    {
        $app = new TestApp(__DIR__);
        $app->boot();

        /** @var ClassRouter $router  */
        $router = $app->getDi()->get(ClassRouter::class);

        // first one is the fallback locale
        $this->assertEquals(["en", "fr"], $router->getAllowedLocales());

        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/fr/lang-module/index/getlang/"));
        $response = (string)$app->handle($request)->getBody();
        $this->assertEquals("fr", $response);
        // no lang should redirect to a lang
        $request = $request->withUri(new Uri("/lang-module/index/getlang/"));
        $response = $app->handle($request);
        $this->assertEquals(307, $response->getStatusCode());
        $request = $request->withUri(new Uri("/en/lang-module/index/getlang/"));
        $response = (string)$app->handle($request)->getBody();
        $this->assertEquals("en", $response);
        $request = $request->withUri(new Uri("/ja/lang-module/index/getlang/"));
        $response = (string)$app->handle($request)->getBody();
        $this->assertEquals("Invalid locale 'ja'", $response);
    }

    public function testAppInit()
    {
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/"));

        $app = new App(__DIR__);
        $this->assertInstanceOf(App::class, $app);
        $app->boot();
        $this->assertTrue($app->getDebug(), "debug flag is not set");

        $declaredVars = array_keys(get_defined_vars());
        $this->assertNotContains("value_is_not_leaked", $declaredVars);

        $this->assertCount(3, $app->getModules());
        $this->expectOutputString("hello");
        $response = $app->handle($request);
        Http::sendResponse($response);
    }

    public function testRedirect()
    {
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/index/redirect/"));
        $app = new App(__DIR__);
        $app->boot();
        $response = $app->handle($request);
        $this->assertEquals(307, $response->getStatusCode());

        // Deep calls to index should be allowed
        $request = $request->withUri(new Uri("/test-module/index/foo/"));
        $response = $app->handle($request);
        $this->assertNotEquals(307, $response->getStatusCode());

        // Cannot call index action directly => it should call /test-module/
        $request = $request->withUri(new Uri("/test-module/index/"));
        $response = $app->handle($request);
        $this->assertEquals(307, $response->getStatusCode());

        // Cannot call camel style url
        $request = $request->withUri(new Uri("/test-module/Index/"));
        $response = $app->handle($request);
        $this->assertEquals(307, $response->getStatusCode());
        $request = $request->withUri(new Uri("/Test-Module/"));
        $response = $app->handle($request);
        $this->assertEquals(307, $response->getStatusCode());
        $this->assertEquals('/test-module/', $response->getHeaderLine('Location'));
    }

    public function testInvalidHandler()
    {
        $request = Http::createRequestFromGlobals();
        $app = new App(__DIR__);
        $app->boot();
        $request = $request->withUri(new Uri("/test-module/index/isinvalid/"));
        $response = $app->handle($request);
        $this->assertEquals(404, $response->getStatusCode());
        $request = $request->withUri(new Uri("/test-module/index/isinvalid"));
        $response = $app->handle($request);
        $this->assertEquals(307, $response->getStatusCode());
    }

    public function testArrayParams()
    {
        $request = Http::createRequestFromGlobals();
        $app = new App(__DIR__);
        $app->boot();
        $request = $request->withUri(new Uri("/test-module/index/arr/here,is,my/"));
        $response = $app->handle($request);
        $body = (string)$response->getBody();
        $this->assertStringContainsString('"here"', $body);
        $this->assertStringContainsString('"is"', $body);
        $this->assertStringContainsString('"my"', $body);
    }

    public function testAuth()
    {
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/index/auth/"));
        $app = new App(__DIR__);
        $app->boot();
        $response = $app->handle($request);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testMiddleware()
    {
        $middlewareInst = new TestMiddleware();

        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/index/middleware/"));
        $app = new App(__DIR__);
        $app->getMiddlewareRunner()->addMiddleware($middlewareInst);
        $app->boot();
        $response = $app->handle($request);
        $body = (string)$response->getBody();
        $this->assertEquals($middlewareInst->getValue(), $body);

        // updating the middleware will reflect in the new request
        $middlewareInst->setValue("new");
        $response = $app->handle($request);
        $body = (string)$response->getBody();
        $this->assertEquals("new", $body);
    }

    public function testConditionalMiddleware()
    {
        $middlewareInst = new TestMiddleware();

        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/index/middleware/"));
        $app = new App(__DIR__);
        $app->setDebug(true);
        $app->getMiddlewareRunner()->addMiddleware($middlewareInst, function (App $app) {
            return $app->getDebug();
        });
        $app->boot();
        $response = $app->handle($request);
        $body = (string)$response->getBody();
        $this->assertEquals(TestMiddleware::DEFAULT_VALUE, $body);

        // State can change between requests
        $app->setDebug(false);
        $response = $app->handle($request);
        $body = (string)$response->getBody();
        $this->assertNotEquals(TestMiddleware::DEFAULT_VALUE, $body);
    }

    public function testLinearMiddleware()
    {
        $middlewareInst = new TestMiddleware();

        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/index/middleware_exception/"));
        $app = new App(__DIR__);
        $app->setDebug(true);
        $app->getMiddlewareRunner()->addMiddleware($middlewareInst, null, true);
        $app->boot();
        $response = $app->handle($request);
        $body = (string)$response->getBody();
        // It does not appear in the stack trace
        $this->assertStringNotContainsString(TestMiddleware::class, $body);
    }

    public function testRequestHasIp()
    {
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/index/getip/"));
        $app = new App(__DIR__);
        $app->boot();
        $response = $app->handle($request);
        $body = (string)$response->getBody();
        $this->assertNotEmpty($body);

        $request = $request->withUri(new Uri("/test-module/index/getipstate/"));
        $app = new App(__DIR__);
        $app->boot();
        $response = $app->handle($request);
        $body = (string)$response->getBody();
        $this->assertNotEmpty($body);
    }

    public function testValidation()
    {
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/index/validation/"));
        $app = new App(__DIR__);
        $app->boot();
        $response = $app->handle($request);
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testRequestIsCached()
    {
        $app = new App(__DIR__);
        $app->boot();
        $app->setDebug(true);
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/demo/is-request-different/"));
        $response = $app->handle($request);
        $body = (string)$response->getBody();

        $this->assertEquals('no', $body);

        // Since our controller is cached, a new request is passed
        // The initially set "request" object will not be the same as the one from our App class
        // => always use app class
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/demo/is-request-different/"));
        $response = $app->handle($request);
        $body = (string)$response->getBody();

        $this->assertEquals('yes', $body);
    }

    public function testDemoController()
    {
        // (string) always read from the start of the stream while
        // getBody()->getContents() can return an empty response
        $app = new App(__DIR__);
        $app->boot();
        $app->setDebug(true);
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/demo/"));
        $response = $app->handle($request);
        $this->assertEquals("hello demo", (string)$response->getBody());
        $request = $request->withUri(new Uri("/test-module/demo/test/"));
        $response = $app->handle($request);
        $this->assertEquals("hello test", (string)$response->getBody());
        $request = $request->withUri(new Uri("/test-module/demo/func/"));
        $response = $app->handle($request);
        $this->assertEquals("hello func", (string)$response->getBody());
        // Only dashes are converted to camel case. Underscores are valid methods.
        $request = $request->withUri(new Uri("/test-module/demo/hello_func/"));
        $response = $app->handle($request);
        $this->assertEquals("hello underscore", (string)$response->getBody());
        $request = $request->withUri(new Uri("/test-module/demo/arr/he/llo/"));
        $response = $app->handle($request);
        $this->assertEquals("hello he,llo", (string)$response->getBody());
        $request = $request->withUri(new Uri("/test-module/demo/func/he/llo/"));
        $response = $app->handle($request);
        $this->assertEquals("Too many parameters for action 'func' on 'TestModule\Controller\DemoController'", (string)$response->getBody());

        // Test method specific routing
        $request = $request->withUri(new Uri("/test-module/demo/method/"));
        $request = $request->withMethod("GET");
        $response = $app->handle($request);
        $this->assertEquals("get", (string)$response->getBody());
        $request = $request->withUri(new Uri("/test-module/demo/method/"));
        $request = $request->withMethod("POST");
        $response = $app->handle($request);
        $this->assertEquals("post", (string)$response->getBody());
    }

    public function testGenerate()
    {
        $app = new App(__DIR__);
        $app->boot();
        $router = $app->getDi()->get(RouterInterface::class);

        // Route without method
        $str = $router->generate(DemoController::class . "::methodGet");
        $this->assertEquals("/test-module/demo/method/", $str);

        // Include index + param
        // When including parameters, index calls are allowed
        $str = $router->generate(IndexController::class . "::index", ["hello"]);
        $this->assertEquals("/test-module/index/index/hello/", $str);

        // Should not included index
        $str = $router->generate(IndexController::class . "::index");
        $this->assertEquals("/test-module/", $str);
        $str = $router->generate([
            IndexController::class, "index"
        ]);
        $this->assertEquals("/test-module/", $str);
        $str = $router->generate([
            RouterInterface::CONTROLLER => IndexController::class,
        ]);
        $this->assertEquals("/test-module/", $str);

        // Locale
        $str = $router->generate([
            RouterInterface::CONTROLLER => \TestModule\Controller\IndexController::class,
            RouterInterface::LOCALE => 'fr',
        ]);
        $this->assertEquals("/test-module/", $str);
        $str = $router->generate([
            RouterInterface::CONTROLLER => \LangModule\Controller\IndexController::class,
            RouterInterface::ACTION => 'getlang',
            RouterInterface::LOCALE => 'fr',
        ]);
        $this->assertEquals("/fr/lang-module/index/getlang/", $str);

        // Module mapping + no locale
        $str = $router->generate([
            RouterInterface::CONTROLLER => \TestVendor\MappedModule\Controller\IndexController::class,
            RouterInterface::LOCALE => 'fr',
        ]);
        $this->assertEquals("/mapped-module/", $str);

        // Trailing slash
        $router->setForceTrailingSlash(false);
        $str = $router->generate([
            RouterInterface::CONTROLLER => IndexController::class,
        ]);
        $this->assertEquals("/test-module", $str);
        $router->setForceTrailingSlash(true);
    }

    public function testTrailingSlash()
    {
        $app = new App(__DIR__);
        $app->boot();
        $app->setDebug(true);
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/demo"));
        $response = $app->handle($request);
        $this->assertEquals("You are being redirected to /test-module/demo/", (string)$response->getBody());
    }
}
