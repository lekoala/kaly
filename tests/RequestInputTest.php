<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\Ex;
use Kaly\Core\HttpContext;
use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use Kaly\Router\ClassRouter;
use Kaly\Router\RequestDispatcher;
use Kaly\Router\Route;
use Kaly\Router\RouteNotFoundException;
use Kaly\Router\RouterInterface;
use Kaly\Router\RoutingHandler;
use Kaly\Tests\Mocks\SearchInput;
use Kaly\Tests\Support\HttpFactory;
use Kaly\Text\LocaleResolver;
use Kaly\Text\Translator;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RequestInputTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    private function router(): ClassRouter
    {
        $router = new ClassRouter();
        $router->addAllowedNamespace('TestModule');
        return $router;
    }

    private function match(string $path): Route
    {
        return $this->router()->match((new Psr17Factory())->createServerRequest('GET', $path));
    }

    private function request(string $path, string $method = 'GET'): ResponseInterface
    {
        $app = new App(__DIR__);
        $app->boot();

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri($path))->withMethod($method);
        parse_str($request->getUri()->getQuery(), $query);
        return $app->handle($request->withQueryParams($query));
    }

    public function testTrailingInputDoesNotConsumeASegment(): void
    {
        $response = $this->request('/test-module/input/search/?q=hello&page=2');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello:2', (string) $response->getBody());
    }

    public function testTrailingInputUsesItsDefaultsWhenNothingIsSent(): void
    {
        $response = $this->request('/test-module/input/search/');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(':1', (string) $response->getBody());
    }

    public function testASegmentIsStillMappedToAScalarParameter(): void
    {
        $response = $this->request('/test-module/input/edit/12/?q=hello');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('12:hello', (string) $response->getBody());
    }

    public function testAnInvalidSegmentIsStillARoutingFailure(): void
    {
        // The route itself does not match: this is a 404, not a bad input
        $response = $this->request('/test-module/input/edit/abc/?q=hello');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAnExtraSegmentIsRefusedEvenWithAnInput(): void
    {
        $this->expectException(RouteNotFoundException::class);
        $this->expectExceptionMessage("Too many parameters for action 'search'");
        $this->match('/test-module/input/search/extra/');
    }

    public function testTheRouteExposesTheInputClass(): void
    {
        $route = $this->match('/test-module/input/search/');
        $this->assertSame(SearchInput::class, $route->inputClass);
        // The input is not a route param
        $this->assertSame([], $route->params);

        $route = $this->match('/test-module/input/edit/12/');
        $this->assertSame(SearchInput::class, $route->inputClass);
        $this->assertSame([12], $route->params);
    }

    public function testAnActionWithoutAnInputHasNoInputClass(): void
    {
        $route = $this->match('/test-module/index/typed-int/12/');
        $this->assertNull($route->inputClass);
    }

    public function testAnInputThatIsNotTheLastParameterIsRefused(): void
    {
        $this->expectException(Ex::class);
        $this->expectExceptionMessage("must be the last parameter of action 'badOrder'");
        $this->match('/test-module/input/bad-order/12/');
    }

    public function testAServiceCannotBeAnActionParameter(): void
    {
        // This used to be silently resolved from the container
        $this->expectException(Ex::class);
        $this->expectExceptionMessage("Parameter 'logger' of action 'withService'");
        $this->match('/test-module/input/with-service/');
    }

    public function testAnInputWithoutAMapperIsAnError(): void
    {
        $router = new class implements RouterInterface {
            public function match(ServerRequestInterface $request): Route
            {
                $route = new Route();
                $route->controller = \TestModule\Controller\InputController::class;
                $route->action = 'search';
                $route->inputClass = SearchInput::class;
                return $route;
            }

            public function generate($handler, array $params = []): string
            {
                return '';
            }
        };

        $factory = new Psr17Factory();
        $dispatcher = new RequestDispatcher(new Injector(new Container(new Definitions())), new Translator('en'), $factory, $factory);
        $handler = new RoutingHandler($router, new LocaleResolver('en', ['en']), $dispatcher);

        $request = $factory->createServerRequest('GET', '/');
        $ctx = new HttpContext($request);

        $this->expectException(Ex::class);
        $this->expectExceptionMessage('bind a Kaly\Http\InputMapperInterface');
        $handler->handle($ctx->bind($request));
    }
}
