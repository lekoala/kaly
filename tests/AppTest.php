<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Http\ContentType;
use Kaly\Router\ClassRouter;
use Kaly\Router\RouterInterface;
use Kaly\Tests\Mocks\TestApp;
use Kaly\Tests\Mocks\TestMiddleware;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use TestModule\Controller\DemoController;
use TestModule\Controller\IndexController;

class AppTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV[App::ENV_DEBUG] = true;
    }

    protected function tearDown(): void
    {
        // restore_error_handler();
        ErrorHandler::restoreDefaults();
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
        $this->assertEquals(['en', 'fr'], $router->getAllowedLocales());

        // The controller runs with the locale applied before dispatch
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri('/fr/lang-module/index/getlang/'));
        $response = $app->handle($request);
        $this->assertSame('fr', (string) $response->getBody());

        $request = $request->withUri(new Uri('/en/lang-module/index/getlang/'));
        $response = $app->handle($request);
        $this->assertSame('en', (string) $response->getBody());

        // No locale prefix on a restricted module redirects to a locale
        $request = $request->withUri(new Uri('/lang-module/index/getlang/'));
        $response = $app->handle($request);
        $this->assertEquals(307, $response->getStatusCode());
    }

    public function testAppInit(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri('/test-module/'));

        $app = new App(__DIR__);
        $this->assertInstanceOf(App::class, $app);
        $app->boot();
        $this->assertTrue($app->getDebug(), 'debug flag is not set');

        $declaredVars = array_keys(get_defined_vars());
        $this->assertNotContains('value_is_not_leaked', $declaredVars);

        $this->assertCount(3, $app->getModules());
        $this->expectOutputString('hello');
        $response = $app->handle($request);

        HttpFactory::sendResponse($response);
    }

    public function testRedirect(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri('/test-module/index/redirect/'));
        $app = new App(__DIR__);
        $app->boot();
        $response = $app->handle($request);
        $this->assertEquals(307, $response->getStatusCode());

        // Deep calls to index should be allowed
        $request = $request->withUri(new Uri('/test-module/index/foo/'));
        $response = $app->handle($request);
        $this->assertNotEquals(307, $response->getStatusCode());

        // Cannot call index action directly => it should call /test-module/
        $request = $request->withUri(new Uri('/test-module/index/'));
        $response = $app->handle($request);
        $this->assertEquals(307, $response->getStatusCode());

        // Modules are always lower case
        $request = $request->withUri(new Uri('/Test-Module/'));
        $response = $app->handle($request);
        $this->assertEquals(307, $response->getStatusCode());
        $this->assertEquals('/test-module/', $response->getHeaderLine('Location'));

        // Cannot call index, even with wrong casing
        $request = $request->withUri(new Uri('/test-module/Index/'));
        $response = $app->handle($request);
        $this->assertEquals(307, $response->getStatusCode());
    }

    public function testInvalidHandler(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
        $app = new App(__DIR__);
        $app->boot();

        // Action must exists and public
        $request = $request->withUri(new Uri('/test-module/index/isinvalid/'));
        $response = $app->handle($request);
        $this->assertEquals(404, $response->getStatusCode());

        // Actions are case sensitive
        $request = $request->withUri(new Uri('/test-module/FOO/'));
        $response = $app->handle($request);
        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testArrayParams(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
        $app = new App(__DIR__);
        $app->boot();
        $request = $request->withUri(new Uri('/test-module/index/arr/here,is,my/'));
        $response = $app->handle($request);
        $body = (string) $response->getBody();
        $this->assertStringContainsString('"here"', $body);
        $this->assertStringContainsString('"is"', $body);
        $this->assertStringContainsString('"my"', $body);
    }

    public function testJsonRoute(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri('/test-module/json/'));
        $app = new App(__DIR__);
        $app->boot();
        $response = $app->handle($request);
        // $body = (string)$response->getBody();
        $this->assertEquals(ContentType::JSON, $response->getHeaderLine('Content-type'));
    }

    public function testViewController(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri('/test-module/index/view/'));
        $app = new App(__DIR__);
        $app->boot();
        $response = $app->handle($request);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(ContentType::HTML, $response->getHeaderLine('Content-type'));
        $this->assertStringContainsString('View test', (string) $response->getBody());
    }

    public function testControllerResultContract(): void
    {
        $app = new App(__DIR__);
        $app->boot();
        $base = HttpFactory::createRequestFromGlobals();

        // string => HTML
        $response = $app->handle($base->withUri(new Uri('/test-module/index/foo/')));
        $this->assertEquals(ContentType::HTML, $response->getHeaderLine('Content-type'));
        $this->assertSame('foo', (string) $response->getBody());

        // array => JSON
        $response = $app->handle($base->withUri(new Uri('/test-module/json/')));
        $this->assertEquals(ContentType::JSON, $response->getHeaderLine('Content-type'));
        $this->assertSame('[]', (string) $response->getBody());

        // null => empty response
        $response = $app->handle($base->withUri(new Uri('/test-module/index/noop/')));
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());

        // ResponseInterface is used as-is
        $response = $app->handle($base->withUri(new Uri('/test-module/index/raw/')));
        $this->assertEquals(201, $response->getStatusCode());
        $this->assertSame('yes', $response->getHeaderLine('X-Raw'));
        $this->assertSame('raw', (string) $response->getBody());
    }

    public function testMultipleRequestsOnSameApp(): void
    {
        $app = new App(__DIR__);
        $app->boot();
        $base = HttpFactory::createRequestFromGlobals();

        // Sequential requests must not leak state between each other
        $response = $app->handle($base->withUri(new Uri('/test-module/index/foo/')));
        $this->assertSame('foo', (string) $response->getBody());

        $response = $app->handle($base->withUri(new Uri('/test-module/demo/')));
        $this->assertSame('hello demo', (string) $response->getBody());

        $response = $app->handle($base->withUri(new Uri('/test-module/index/foo/')));
        $this->assertSame('foo', (string) $response->getBody());

        // The kernel is built once and reused
        $this->assertSame($app->getKernel(), $app->getKernel());
    }

    public function testRequestCallbacks(): void
    {
        $app = new App(__DIR__);
        $app->boot();

        $before = 0;
        $after = 0;
        $errors = 0;
        $app->addCallback(App::CB_BEFORE_REQUEST, function () use (&$before): void {
            $before++;
        });
        $app->addCallback(App::CB_AFTER_REQUEST, function () use (&$after): void {
            $after++;
        });
        $app->addCallback(App::CB_ERROR, function () use (&$errors): void {
            $errors++;
        });

        $base = HttpFactory::createRequestFromGlobals();

        // A normal request
        $app->handle($base->withUri(new Uri('/test-module/index/foo/')));
        $this->assertSame(1, $before);
        $this->assertSame(1, $after);
        $this->assertSame(0, $errors);

        // HTTP exceptions are expected and are not reported as errors
        $response = $app->handle($base->withUri(new Uri('/test-module/index/validation/')));
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $errors);

        // Generic errors are reported
        $response = $app->handle($base->withUri(new Uri('/test-module/index/middlewareexception/')));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(1, $errors);
        $this->assertSame(3, $before);
        $this->assertSame(3, $after);
    }

    public function testFailingBeforeRequestCallbackReturnsResponse(): void
    {
        $app = new App(__DIR__);
        $app->boot();
        $errors = 0;
        $app->addCallback(App::CB_BEFORE_REQUEST, function (): void {
            throw new \RuntimeException('before failed');
        });
        $app->addCallback(App::CB_ERROR, function () use (&$errors): void {
            $errors++;
        });

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/foo/'));
        $response = $app->handle($request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(1, $errors);
    }

    public function testFailingAfterRequestCallbackDoesNotMaskResponse(): void
    {
        $app = new App(__DIR__);
        $app->boot();
        $app->addCallback(App::CB_AFTER_REQUEST, function (): void {
            throw new \RuntimeException('after failed');
        });

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/foo/'));
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('foo', (string) $response->getBody());
    }

    public function testMiddleware(): void
    {
        $middlewareInst = new TestMiddleware();

        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri('/test-module/index/middleware/'));
        $app = new App(__DIR__);
        $app->boot();
        $app->getMiddlewareRunner()->unshift($middlewareInst);
        $response = $app->handle($request);
        $body = (string) $response->getBody();
        $this->assertEquals($middlewareInst->getValue(), $body);

        // updating the middleware will reflect in the new request
        $middlewareInst->setValue('new');
        $response = $app->handle($request);
        $body = (string) $response->getBody();
        $this->assertEquals('new', $body);
    }

    public function testConditionalMiddleware(): void
    {
        $middlewareInst = new TestMiddleware();

        $flag = true;
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri('/test-module/index/middleware/'));
        $app = new App(__DIR__);
        $app->setDebug(true);
        $app->boot();

        // if condition returns true, it means execute
        $app->getMiddlewareRunner()->unshift($middlewareInst, static function () use (&$flag): bool {
            return $flag;
        });

        $response = $app->handle($request);
        $body = (string) $response->getBody();
        $this->assertNotEquals(404, $response->getStatusCode());
        $this->assertEquals(TestMiddleware::DEFAULT_VALUE, $body);

        // State can change between requests
        $flag = false;
        $response = $app->handle($request);
        $body = (string) $response->getBody();
        $this->assertNotEquals(404, $response->getStatusCode());
        $this->assertNotEquals(TestMiddleware::DEFAULT_VALUE, $body);
    }

    public function testRequestHasIp(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri('/test-module/index/getip/'));
        $app = new App(__DIR__);
        $app->boot();
        $response = $app->handle($request);
        $body = (string) $response->getBody();
        $this->assertNotEmpty($body);

        $request = $request->withUri(new Uri('/test-module/index/getipstate/'));
        $app = new App(__DIR__);
        $app->boot();
        $response = $app->handle($request);
        $body = (string) $response->getBody();
        $this->assertNotEmpty($body);
    }

    public function testValidation(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri('/test-module/index/validation/'));
        $app = new App(__DIR__);
        $app->boot();
        $response = $app->handle($request);
        $this->assertEquals(403, $response->getStatusCode(), 'Error with : ' . (string) $response->getBody());
    }

    public function testDemoController(): void
    {
        // (string) always read from the start of the stream while
        // getBody()->getContents() can return an empty response
        $app = new App(__DIR__);
        $app->boot();
        $app->setDebug(true);
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri('/test-module/demo/'));
        $response = $app->handle($request);
        $this->assertEquals('hello demo', (string) $response->getBody());

        // TestModule > DemoController > index with test param
        $request = $request->withUri(new Uri('/test-module/demo/test/'));
        $response = $app->handle($request);
        $this->assertEquals('hello test', (string) $response->getBody());

        $request = $request->withUri(new Uri('/test-module/demo/func/'));
        $response = $app->handle($request);
        $this->assertEquals('hello func', (string) $response->getBody());

        // Only dashes are converted to camel case. Underscores are valid methods.
        $request = $request->withUri(new Uri('/test-module/demo/hello_func/'));
        $response = $app->handle($request);
        $this->assertEquals('hello underscore', (string) $response->getBody());
        $request = $request->withUri(new Uri('/test-module/demo/arr/he/llo/'));

        $response = $app->handle($request);
        $this->assertEquals('hello he,llo', (string) $response->getBody());

        $request = $request->withUri(new Uri('/test-module/demo/arrplus/he/llo/'));
        $response = $app->handle($request);
        $this->assertEquals('hello he,llo', (string) $response->getBody());

        $request = $request->withUri(new Uri('/test-module/demo/func/he/llo/'));
        try {
            $response = $app->handle($request);
        } catch (\Exception $e) {
            $this->assertStringContainsString(
                "Too many parameters for action 'func' on 'TestModule\Controller\DemoController'",
                (string) $e->getMessage(),
            );
        }

        // Test method specific routing
        $request = $request->withUri(new Uri('/test-module/demo/method/'));
        $request = $request->withMethod('GET');
        $response = $app->handle($request);
        $this->assertEquals('get', (string) $response->getBody());
        $request = $request->withUri(new Uri('/test-module/demo/method/'));
        $request = $request->withMethod('POST');
        $response = $app->handle($request);
        $this->assertEquals('post', (string) $response->getBody());
    }

    public function testGenerate(): void
    {
        $app = new App(__DIR__);
        $app->boot();
        $router = $app->getContainer()->get(RouterInterface::class);

        // Route without method
        $str = $router->generate(DemoController::class . '::methodGet');
        // $this->assertEquals("/test-module/demo/method/", $str);

        // Include index + param
        // When including parameters, index calls are allowed
        $str = $router->generate(IndexController::class . '::index', ['hello']);
        $this->assertEquals('/test-module/index/index/hello/', $str);

        // Should not included index
        $str = $router->generate(IndexController::class . '::index');
        $this->assertEquals('/test-module/', $str);
        $str = $router->generate([
            IndexController::class,
            'index',
        ]);
        $this->assertEquals('/test-module/', $str);
        $str = $router->generate([
            RouterInterface::CONTROLLER => IndexController::class,
        ]);
        $this->assertEquals('/test-module/', $str);

        // Locale
        $str = $router->generate([
            RouterInterface::CONTROLLER => \TestModule\Controller\IndexController::class,
            RouterInterface::LOCALE => 'fr',
        ]);
        $this->assertEquals('/test-module/', $str);
        $str = $router->generate([
            RouterInterface::CONTROLLER => \LangModule\Controller\IndexController::class,
            RouterInterface::ACTION => 'getlang',
            RouterInterface::LOCALE => 'fr',
        ]);
        $this->assertEquals('/fr/lang-module/index/getlang/', $str);

        // Module mapping + no locale
        $str = $router->generate([
            RouterInterface::CONTROLLER => \TestVendor\MappedModule\Controller\IndexController::class,
            RouterInterface::LOCALE => 'fr',
        ]);
        $this->assertEquals('/mapped-module/', $str);

        // Trailing slash
        /*
         * $router->setForceTrailingSlash(false);
         * $str = $router->generate([
         * RouterInterface::CONTROLLER => IndexController::class,
         * ]);
         * $this->assertEquals("/test-module", $str);
         * $router->setForceTrailingSlash(true);*/
    }

    public function testTrailingSlash(): void
    {
        $app = new App(__DIR__);
        $app->boot();
        $app->setDebug(true);
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri('/test-module/demo'));
        $response = $app->handle($request);
        $this->assertEquals('You are being redirected to /test-module/demo/', (string) $response->getBody());
    }
}
