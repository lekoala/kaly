<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\App;
use Kaly\Http;
use Nyholm\Psr7\Uri;
use Kaly\ClassRouter;
use Kaly\Tests\Mocks\TestApp;
use PHPUnit\Framework\TestCase;

class AppTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV['DEBUG'] = true;
    }

    public function testAppExtension()
    {
        $app = new TestApp(__DIR__);
        $app->boot();
        $di = $app->getDi();

        $this->assertTrue($di->has(App::class));
        $this->assertTrue($di->has(TestApp::class));
    }

    /**
     * @group only
     */
    public function testLocaleDetection()
    {
        $app = new TestApp(__DIR__);
        $app->boot();

        /** @var ClassRouter $router  */
        $router = $app->getDi()->get(ClassRouter::class);

        // first one is the fallback locale
        $this->assertEquals(["en", "fr"], $router->getAllowedLocales());

        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/fr/lang-module/getlang/"));
        $response = (string)$app->handle($request)->getBody();
        $this->assertEquals("fr", $response);
        // no lang should redirect to a lang
        $request = $request->withUri(new Uri("/lang-module/getlang/"));
        $response = $app->handle($request);
        $this->assertEquals(307, $response->getStatusCode());
        $request = $request->withUri(new Uri("/en/lang-module/getlang/"));
        $response = (string)$app->handle($request)->getBody();
        $this->assertEquals("en", $response);
        $request = $request->withUri(new Uri("/ja/lang-module/getlang/"));
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

        $this->assertCount(2, $app->getModules());
        $this->expectOutputString("hello");
        $response = $app->handle($request);
        Http::sendResponse($response);
    }

    public function testRedirect()
    {
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/redirect/"));
        $app = new App(__DIR__);
        $app->boot();
        $response = $app->handle($request);
        $this->assertEquals(307, $response->getStatusCode());

        // Cannot call index controller directly => it should call /test-module/foo/
        $request = $request->withUri(new Uri("/test-module/index/foo/"));
        $response = $app->handle($request);
        $this->assertEquals(307, $response->getStatusCode());

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

    public function testAuth()
    {
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/auth/"));
        $app = new App(__DIR__);
        $app->boot();
        $response = $app->handle($request);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testValidation()
    {
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/validation/"));
        $app = new App(__DIR__);
        $app->boot();
        $response = $app->handle($request);
        $this->assertEquals(403, $response->getStatusCode());
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
        $request = $request->withUri(new Uri("/test-module/demo/arr/he/llo/"));
        $response = $app->handle($request);
        $this->assertEquals("hello he,llo", (string)$response->getBody());
        $request = $request->withUri(new Uri("/test-module/demo/func/he/llo/"));
        $response = $app->handle($request);
        $this->assertEquals("Too many parameters for action 'func' on 'TestModule\Controller\DemoController'", (string)$response->getBody());
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
