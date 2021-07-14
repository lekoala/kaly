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
        $di = $app->boot($request);
        $this->assertTrue($app->getDebug(), "debug flag is not set");

        $declaredVars = array_keys(get_defined_vars());
        $this->assertNotContains("value_is_not_leaked", $declaredVars);

        $this->assertCount(1, $app->getModules());
        $this->expectOutputString("hello");
        $app->handle($request, $di);
        unset($_ENV['DEBUG']);
    }

    public function testDi()
    {
        $request = Http::createRequestFromGlobals();
        $app = new App(__DIR__);
        $di = $app->boot($request);
        $this->assertInstanceOf(ServerRequestInterface::class, $di->get(ServerRequestInterface::class));
        $this->assertInstanceOf(TestInterface::class, $di->get(TestInterface::class));
    }

    public function testRedirect()
    {
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/redirect"));
        $app = new App(__DIR__);
        $di = $app->boot($request);
        $response = $app->processRequest($request, $di);
        $this->assertEquals(307, $response->getStatusCode());
    }

    public function testAuth()
    {
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/auth"));
        $app = new App(__DIR__);
        $di = $app->boot($request);
        $response = $app->processRequest($request, $di);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testValidation()
    {
        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/test-module/validation"));
        $app = new App(__DIR__);
        $di = $app->boot($request);
        $response = $app->processRequest($request, $di);
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testWrongInit()
    {
        $request = Http::createRequestFromGlobals();
        $_ENV['debug'] = true;
        $app = new App(__DIR__);
        $this->assertInstanceOf(App::class, $app);
        $app->boot($request);
        $this->assertFalse($app->getDebug(), "debug must be lowercase");
        unset($_ENV['debug']);
    }
}
