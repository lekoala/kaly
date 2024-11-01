<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Nyholm\Psr7\Uri;
use Kaly\Tests\Mocks\TestApp;
use Kaly\Tests\Mocks\TestMiddleware;
use PHPUnit\Framework\TestCase;
use TestModule\Controller\DemoController;
use TestModule\Controller\IndexController;
use Kaly\Core\App;
use Kaly\Http\ContentType;
use Kaly\Http\HttpFactory;
use Kaly\Http\ResponseEmitter;
use Kaly\Router\ClassRouter;
use Kaly\Security\Auth;
use Kaly\Router\RouterInterface;

class AppTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV[App::ENV_DEBUG] = true;
    }

    public function testAppExtension(): void
    {
        $app = new TestApp(__DIR__);
        $app->boot();
        $di = $app->getContainer();

        $this->assertTrue($di->has(App::class));
        $this->assertTrue($di->has(TestApp::class));
    }

    public function testLocaleDetection(): void
    {
        $app = new TestApp(__DIR__);
        $app->boot();

        /** @var ClassRouter $router  */
        $router = $app->getContainer()->get(ClassRouter::class);

        // first one is the fallback locale
        $this->assertEquals(["en", "fr"], $router->getAllowedLocales());

        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/fr/lang-module/index/getlang/"));
        $response = (string)$app->handle($request)->getBody();

        /*
        $appRequest = $app->getRequest();
        $this->assertEquals("fr", $response);
        $this->assertEquals("fr", $appRequest->getAttribute(App::ATTR_LOCALE_REQUEST));
        // no lang should redirect to a lang
        $request = $request->withUri(new Uri("/lang-module/index/getlang/"));
        $response = $app->handle($request);
        $this->assertEquals(307, $response->getStatusCode());
        $request = $request->withUri(new Uri("/en/lang-module/index/getlang/"));
        $response = (string)$app->handle($request)->getBody();
        $this->assertEquals("en", $response);
        $request = $request->withUri(new Uri("/ja/lang-module/index/getlang/"));
        $response = (string)$app->handle($request)->getBody();
        $this->assertStringContainsString("not found", $response);*/
    }

    public function testAppInit(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/"));

        $app = new App(__DIR__);
        $this->assertInstanceOf(App::class, $app);
        $app->boot();
        $this->assertTrue($app->getDebug(), "debug flag is not set");

        $cookiesParams = session_get_cookie_params();
        $this->assertEquals(1, $cookiesParams['httponly']);

        $declaredVars = array_keys(get_defined_vars());
        $this->assertNotContains("value_is_not_leaked", $declaredVars);

        $this->assertCount(3, $app->getModules());
        $this->expectOutputString("hello");
        $response = $app->handle($request);

        HttpFactory::sendResponse($response);
    }

    public function testRedirect(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
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

    public function testInvalidHandler(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
        $app = new App(__DIR__);
        $app->boot();
        $request = $request->withUri(new Uri("/test-module/index/isinvalid/"));
        $response = $app->handle($request);
        $this->assertEquals(404, $response->getStatusCode());
        $request = $request->withUri(new Uri("/test-module/index/isinvalid"));
        $response = $app->handle($request);
        $this->assertEquals(307, $response->getStatusCode());
    }

    public function testArrayParams(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
        $app = new App(__DIR__);
        $app->boot();
        $request = $request->withUri(new Uri("/test-module/index/arr/here,is,my/"));
        $response = $app->handle($request);
        $body = (string)$response->getBody();
        $this->assertStringContainsString('"here"', $body);
        $this->assertStringContainsString('"is"', $body);
        $this->assertStringContainsString('"my"', $body);
    }

    public function testAuth(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/index/auth/"));
        $app = new App(__DIR__);
        $app->boot();
        $response = $app->handle($request);
        $this->assertEquals(401, $response->getStatusCode());

        /** @var Auth $auth  */
        $auth = $app->getContainer()->get(Auth::class);
        $auth->setUser("test");
        // App request has been modified by reference
        // $this->assertEquals("test", $app->getRequest()->getAttribute(Auth::KEY_USER_ID));
        $this->assertEquals("test", $_SESSION[Auth::KEY_USER_ID]);
        // Original request is not mutable and as been copied by handle
        $this->assertNotEquals("test", $request->getAttribute(Auth::KEY_USER_ID));
    }

    /**
     */
    public function testJsonRoute(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/json/"));
        $app = new App(__DIR__);
        $app->boot();
        $response = $app->handle($request);
        // $body = (string)$response->getBody();
        $this->assertEquals(ContentType::JSON, $response->getHeaderLine('Content-type'));
    }

    public function testMiddleware(): void
    {
        $middlewareInst = new TestMiddleware();

        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/index/middleware/"));
        $app = new App(__DIR__);
        $app->boot();
        $app->getMiddlewareRunner()->unshift($middlewareInst);
        $response = $app->handle($request);
        $body = (string)$response->getBody();
        $this->assertEquals($middlewareInst->getValue(), $body);

        // updating the middleware will reflect in the new request
        $middlewareInst->setValue("new");
        $response = $app->handle($request);
        $body = (string)$response->getBody();
        $this->assertEquals("new", $body);
    }

    /**
     */
    public function testConditionalMiddleware(): void
    {
        $middlewareInst = new TestMiddleware();

        $flag = true;
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/index/middleware/"));
        $app = new App(__DIR__);
        $app->setDebug(true);
        $app->boot();

        // if condition returns true, it means execute
        $app->getMiddlewareRunner()->unshift($middlewareInst, function () use (&$flag): bool {
            return $flag;
        });

        $response = $app->handle($request);
        $body = (string)$response->getBody();
        $this->assertNotEquals(404, $response->getStatusCode());
        $this->assertEquals(TestMiddleware::DEFAULT_VALUE, $body);

        // State can change between requests
        $flag = false;
        $response = $app->handle($request);
        $body = (string)$response->getBody();
        $this->assertNotEquals(404, $response->getStatusCode());
        $this->assertNotEquals(TestMiddleware::DEFAULT_VALUE, $body);
    }

    public function testLinearMiddleware(): void
    {
        $middlewareInst = new TestMiddleware();

        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/index/middleware-exception/"));
        $app = new App(__DIR__);
        $app->setDebug(true);
        $app->boot();
        $app->getMiddlewareRunner()->push($middlewareInst, null, true);

        $response = $app->handle($request);
        $body = (string)$response->getBody();
        $this->assertNotEquals(404, $response->getStatusCode());
        // It does not appear in the stack trace
        $this->assertStringNotContainsString(TestMiddleware::class, $body);
    }

    public function testRequestHasIp(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
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

    /**
     * @group only
     */
    public function testValidation(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/index/validation/"));
        $app = new App(__DIR__);
        $app->boot();
        $response = $app->handle($request);
        $this->assertEquals(403, $response->getStatusCode(), "Error with : " . (string)$response->getBody());
    }

    public function testDemoController(): void
    {
        // (string) always read from the start of the stream while
        // getBody()->getContents() can return an empty response
        $app = new App(__DIR__);
        $app->boot();
        $app->setDebug(true);
        $request = HttpFactory::createRequestFromGlobals();
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
        $request = $request->withUri(new Uri("/test-module/demo/arrplus/he/llo/"));
        $response = $app->handle($request);
        $this->assertEquals("hello he,llo", (string)$response->getBody());
        $request = $request->withUri(new Uri("/test-module/demo/func/he/llo/"));
        try {
            $response = $app->handle($request);
        } catch (\Exception $e) {
            $this->assertStringContainsString(
                "Too many parameters for action 'func' on 'TestModule\Controller\DemoController'",
                (string)$e->getMessage()
            );
        }

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

    public function testGenerate(): void
    {
        $app = new App(__DIR__);
        $app->boot();
        $router = $app->getContainer()->get(RouterInterface::class);

        // Route without method
        $str = $router->generate(DemoController::class . "::methodGet");
        // $this->assertEquals("/test-module/demo/method/", $str);

        // Include index + param
        // When including parameters, index calls are allowed
        $str = $router->generate(IndexController::class . "::index", ["hello"]);
        $this->assertEquals("/test-module/index/index/hello/", $str);

        // Should not included index
        $str = $router->generate(IndexController::class . "::index");
        $this->assertEquals("/test-module/", $str);
        $str = $router->generate([
            IndexController::class,
            "index"
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
        /*
        $router->setForceTrailingSlash(false);
        $str = $router->generate([
            RouterInterface::CONTROLLER => IndexController::class,
        ]);
        $this->assertEquals("/test-module", $str);
        $router->setForceTrailingSlash(true);*/
    }

    public function testTrailingSlash(): void
    {
        $app = new App(__DIR__);
        $app->boot();
        $app->setDebug(true);
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/demo"));
        $response = $app->handle($request);
        $this->assertEquals("You are being redirected to /test-module/demo/", (string)$response->getBody());
    }
}
