<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\App;
use Kaly\Http;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Kaly\Tests\Mocks\TestInterface;
use Psr\Http\Message\ServerRequestInterface;

class AppTest extends TestCase
{
    public function testAppInit()
    {
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module"));
        $_ENV['DEBUG'] = true;
        $app = new App(__DIR__);
        $this->assertInstanceOf(App::class, $app);
        $app->boot();
        $this->assertTrue($app->getDebug(), "debug flag is not set");

        $declaredVars = array_keys(get_defined_vars());
        $this->assertNotContains("value_is_not_leaked", $declaredVars);

        $this->assertCount(1, $app->getModules());
        $this->expectOutputString("hello");
        $app->handle($request);
        unset($_ENV['DEBUG']);
    }

    public function testDi()
    {
        $request = Http::createRequestFromGlobals();
        $app = new App(__DIR__);
        $app->boot();
        $di = $app->configureDi($request);
        $this->assertInstanceOf(ServerRequestInterface::class, $di->get(ServerRequestInterface::class));
        $this->assertInstanceOf(TestInterface::class, $di->get(TestInterface::class));
    }

    public function testRedirect()
    {
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/redirect"));
        $app = new App(__DIR__);
        $app->boot();
        $di = $app->configureDi($request);
        $response = $app->processRequest($request, $di);
        $this->assertEquals(307, $response->getStatusCode());
    }

    public function testAuth()
    {
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/auth"));
        $app = new App(__DIR__);
        $app->boot();
        $di = $app->configureDi($request);
        $response = $app->processRequest($request, $di);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testValidation()
    {
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/validation"));
        $app = new App(__DIR__);
        $app->boot();
        $di = $app->configureDi($request);
        $response = $app->processRequest($request, $di);
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
        $request = $request->withUri(new Uri("/test-module/demo"));
        $di = $app->configureDi($request);
        $response = $app->processRequest($request, $di);
        $this->assertEquals("hello demo", (string)$response->getBody());
        $request = $request->withUri(new Uri("/test-module/demo/test"));
        $di = $app->configureDi($request);
        $response = $app->processRequest($request, $di);
        $this->assertEquals("hello test", (string)$response->getBody());
        $request = $request->withUri(new Uri("/test-module/demo/func"));
        $di = $app->configureDi($request);
        $response = $app->processRequest($request, $di);
        $this->assertEquals("hello func", (string)$response->getBody());
        $request = $request->withUri(new Uri("/test-module/demo/arr/he/llo"));
        $di = $app->configureDi($request);
        $response = $app->processRequest($request, $di);
        $this->assertEquals("hello he,llo", (string)$response->getBody());
        $request = $request->withUri(new Uri("/test-module/demo/func/he/llo"));
        $di = $app->configureDi($request);
        $response = $app->processRequest($request, $di);
        $this->assertEquals("Too many parameters for action 'func' on 'TestModule\Controller\DemoController'", (string)$response->getBody());
    }

    public function testWrongInit()
    {
        $request = Http::createRequestFromGlobals();
        $_ENV['debug'] = true;
        $app = new App(__DIR__);
        $this->assertInstanceOf(App::class, $app);
        $app->boot();
        $this->assertFalse($app->getDebug(), "debug must be lowercase");
        unset($_ENV['debug']);
    }
}
