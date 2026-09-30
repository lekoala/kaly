<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Di\Definitions;
use Kaly\Http\ContentType;
use Kaly\Http\HttpContext;
use Kaly\Http\ResponseEmitterInterface;
use Kaly\Middleware\Builtin\FileServer;
use Kaly\Router\Route;
use Kaly\Router\Router;
use Kaly\Router\RouterInterface;
use Kaly\Tests\Mocks\ContextProbeMiddleware;
use Kaly\Tests\Mocks\TestMiddleware;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
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

    public function testAppIsRegisteredInTheContainer(): void
    {
        $app = App::create(__DIR__)->boot();

        $this->assertSame($app, $app->container()->get(App::class));
    }

    public function testTheResponseEmitterIsSwappable(): void
    {
        $emitter = new class implements ResponseEmitterInterface {
            public int $emitted = 0;

            public function emit(ResponseInterface $response): bool
            {
                $this->emitted++;
                return true;
            }
        };

        $app = App::create(__DIR__)
            ->configure(static function (Definitions $di) use ($emitter): void {
                $di->set(ResponseEmitterInterface::class, $emitter);
            })
            ->boot();

        $app->run(HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/foo/')));

        $this->assertSame(1, $emitter->emitted);
    }

    public function testHandleBootsTheAppWhenNeeded(): void
    {
        $app = new App(__DIR__);
        $this->assertFalse($app->isBooted());

        $response = $app->handle(HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/foo/')));

        $this->assertTrue($app->isBooted());
        $this->assertSame('foo', (string) $response->getBody());
    }

    public function testContainerCannotChangeOnceBooted(): void
    {
        $app = App::create(__DIR__)->boot();

        $this->expectException(\LogicException::class);
        $app->configure(static function (): void {});
    }

    public function testLocaleDetection(): void
    {
        $app = new App(__DIR__);
        $app->boot();

        $router = $app->container()->get(RouterInterface::class);
        $this->assertInstanceOf(Router::class, $router);

        // first one is the fallback locale
        $this->assertEquals(['en', 'fr'], $router->getLocales());

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
        $this->assertTrue($app->isDebug(), 'debug flag is not set');

        $declaredVars = array_keys(get_defined_vars());
        $this->assertNotContains('value_is_not_leaked', $declaredVars);

        $this->assertCount(3, $app->modules());
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

        // empty string => empty response
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
        $this->assertSame($app->kernel(), $app->kernel());
    }

    public function testRequestCallbacks(): void
    {
        $app = new App(__DIR__);
        $app->boot();

        $after = 0;
        $errors = 0;
        $app->onTerminate(function () use (&$after): void {
            $after++;
        });
        $app->onError(function () use (&$errors): void {
            $errors++;
        });

        $base = HttpFactory::createRequestFromGlobals();

        // A normal request
        $app->handle($base->withUri(new Uri('/test-module/index/foo/')));
        $this->assertSame(1, $after);
        $this->assertSame(0, $errors);

        // HTTP exceptions are expected and are not reported as errors
        $response = $app->handle($base->withUri(new Uri('/test-module/index/validation/')));
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, $errors);

        // Generic errors are reported
        $response = $app->handle($base->withUri(new Uri('/test-module/index/middlewareexception/')));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(1, $errors);
        $this->assertSame(3, $after);
    }

    public function testFailingIncomingMiddlewareReturnsResponse(): void
    {
        $app = new App(__DIR__);
        $app->boot();
        $errors = 0;
        $app->middleware()->incoming(new class implements \Psr\Http\Server\MiddlewareInterface {
            public function process(
                \Psr\Http\Message\ServerRequestInterface $request,
                \Psr\Http\Server\RequestHandlerInterface $handler,
            ): \Psr\Http\Message\ResponseInterface {
                throw new \RuntimeException('before failed');
            }
        });
        $app->onError(function () use (&$errors): void {
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
        $app->onTerminate(function (): void {
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
        $app->middleware()->incoming($middlewareInst);
        $response = $app->handle($request);
        $body = (string) $response->getBody();
        $this->assertEquals($middlewareInst->getValue(), $body);

        // updating the middleware will reflect in the new request
        $middlewareInst->setValue('new');
        $response = $app->handle($request);
        $body = (string) $response->getBody();
        $this->assertEquals('new', $body);
    }

    public function testRoutedBandKnowsTheRouteWhileIncomingDoesNot(): void
    {
        $app = new App(__DIR__);
        $app->boot();

        $incomingRouted = null;
        $routedRoute = null;

        $app
            ->middleware()
            ->incoming(new ContextProbeMiddleware(function (HttpContext $ctx) use (&$incomingRouted): void {
                $incomingRouted = $ctx->hasRoute();
            }))
            ->routed(new ContextProbeMiddleware(function (HttpContext $ctx) use (&$routedRoute): void {
                $routedRoute = $ctx->route();
            }));

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/foo/'));
        $response = $app->handle($request);

        $this->assertSame('foo', (string) $response->getBody());
        $this->assertFalse($incomingRouted, 'nothing is routed yet in the incoming band');
        $this->assertInstanceOf(Route::class, $routedRoute);
        $this->assertSame(IndexController::class, $routedRoute->controller);
        $this->assertSame('foo', $routedRoute->action);
    }

    public function testExecutedMiddlewaresAreTrackedInPipelineOrder(): void
    {
        $app = new App(__DIR__);
        $app->boot();

        $executed = [];
        $app
            ->middleware()
            ->routed(new TestMiddleware())
            ->incoming(new ContextProbeMiddleware(static function (): void {}))
            // Never entered, so it must not show up in the context
            // (and as a class string, it must never even be resolved)
            ->incoming(FileServer::class, when: static fn(): bool => false);

        $app->onTerminate(function (HttpContext $ctx) use (&$executed): void {
            $executed = $ctx->middlewares();
        });

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/middleware/'));
        $response = $app->handle($request);

        $this->assertSame(TestMiddleware::DEFAULT_VALUE, (string) $response->getBody());
        $this->assertSame([ContextProbeMiddleware::class, TestMiddleware::class], $executed);
    }

    public function testConditionalMiddleware(): void
    {
        $middlewareInst = new TestMiddleware();

        $flag = true;
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri('/test-module/index/middleware/'));
        $app = new App(__DIR__);
        $app->debug(true);
        $app->boot();

        // if condition returns true, it means execute
        $app->middleware()->incoming($middlewareInst, when: static function () use (&$flag): bool {
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
        $this->assertEquals(422, $response->getStatusCode(), 'Error with : ' . (string) $response->getBody());
    }

    public function testDemoController(): void
    {
        // (string) always read from the start of the stream while
        // getBody()->getContents() can return an empty response
        $app = new App(__DIR__);
        $app->debug(true);
        $app->boot();
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
        $router = $app->container()->get(RouterInterface::class);

        // When including parameters, index calls are allowed
        $this->assertSame('/test-module/index/index/hello/', $router->urlFor(IndexController::class . '::index', ['hello']));

        // The module index has no controller nor action
        $this->assertSame('/test-module/', $router->urlFor(IndexController::class . '::index'));
        $this->assertSame('/test-module/', $router->urlFor([IndexController::class, 'index']));

        // Rest style actions are reached without their verb suffix
        $this->assertSame('/test-module/demo/method/', $router->urlFor([DemoController::class, 'methodGet']));

        // A module without the locale prefix ignores the locale
        $this->assertSame('/test-module/', $router->urlFor([IndexController::class, 'index'], locale: 'fr'));

        // A localized module carries it
        $this->assertSame('/fr/lang-module/index/getlang/', $router->urlFor(
            [\LangModule\Controller\IndexController::class, 'getlang'],
            locale: 'fr',
        ));
        $this->assertSame('/en/lang-module/index/getlang/', $router->urlFor([\LangModule\Controller\IndexController::class, 'getlang']));

        // A module with its own namespace keeps its mount
        $this->assertSame('/mapped-module/', $router->urlFor([\TestVendor\MappedModule\Controller\IndexController::class, 'index']));
    }

    public function testTrailingSlash(): void
    {
        $app = new App(__DIR__);
        $app->debug(true);
        $app->boot();
        $request = HttpFactory::createRequestFromGlobals();
        $request = $request->withUri(new Uri('/test-module/demo'));
        $response = $app->handle($request);
        $this->assertEquals('You are being redirected to /test-module/demo/', (string) $response->getBody());
    }

    public function testBootLeavesGlobalTimezoneAloneWithoutAppTimezone(): void
    {
        $previousTz = date_default_timezone_get();
        $hadEnv = array_key_exists(App::ENV_TIMEZONE, $_ENV);
        $previousEnv = $_ENV[App::ENV_TIMEZONE] ?? null;
        unset($_ENV[App::ENV_TIMEZONE]);
        putenv(App::ENV_TIMEZONE);
        date_default_timezone_set('Europe/Brussels');
        try {
            new App(__DIR__, false);
            $this->assertSame('Europe/Brussels', date_default_timezone_get());
        } finally {
            date_default_timezone_set($previousTz);
            if ($hadEnv) {
                $_ENV[App::ENV_TIMEZONE] = $previousEnv;
            }
        }
    }

    public function testBootAppliesAppTimezone(): void
    {
        $previousTz = date_default_timezone_get();
        $hadEnv = array_key_exists(App::ENV_TIMEZONE, $_ENV);
        $previousEnv = $_ENV[App::ENV_TIMEZONE] ?? null;
        $_ENV[App::ENV_TIMEZONE] = 'America/New_York';
        try {
            new App(__DIR__, false);
            $this->assertSame('America/New_York', date_default_timezone_get());
        } finally {
            date_default_timezone_set($previousTz);
            if ($hadEnv) {
                $_ENV[App::ENV_TIMEZONE] = $previousEnv;
            } else {
                unset($_ENV[App::ENV_TIMEZONE]);
            }
            putenv(App::ENV_TIMEZONE);
        }
    }

    public function testBootReadsAppTimezoneFromProcessEnv(): void
    {
        $previousTz = date_default_timezone_get();
        $hadEnv = array_key_exists(App::ENV_TIMEZONE, $_ENV);
        $previousEnv = $_ENV[App::ENV_TIMEZONE] ?? null;
        unset($_ENV[App::ENV_TIMEZONE]);
        putenv(App::ENV_TIMEZONE . '=Asia/Tokyo');
        try {
            new App(__DIR__, false);
            $this->assertSame('Asia/Tokyo', date_default_timezone_get());
        } finally {
            date_default_timezone_set($previousTz);
            putenv(App::ENV_TIMEZONE);
            if ($hadEnv) {
                $_ENV[App::ENV_TIMEZONE] = $previousEnv;
            }
        }
    }
}
