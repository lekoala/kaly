<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\Ex;
use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use Kaly\Middleware\NullHandler;
use Kaly\Router\RequestDispatcher;
use Kaly\Router\Route;
use Kaly\Router\RouterInterface;
use Kaly\Tests\Mocks\DispatcherController;
use Kaly\Text\Translator;
use Kaly\View\RendererInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

class RequestDispatcherTest extends TestCase
{
    private function dispatcher(string $action, ?RendererInterface $renderer = null): RequestDispatcher
    {
        $router = new class($action) implements RouterInterface {
            public function __construct(
                private string $action,
            ) {}

            public function match(ServerRequestInterface $request): Route
            {
                $route = new Route();
                $route->controller = DispatcherController::class;
                $route->action = $this->action;
                return $route;
            }

            public function generate($handler, array $params = []): string
            {
                return '';
            }
        };

        $container = new Container(new Definitions());
        $injector = new Injector($container);
        $factory = new Psr17Factory();

        return new RequestDispatcher($router, $injector, new Translator(), $factory, $factory, $renderer);
    }

    private function dispatch(RequestDispatcher $dispatcher): \Psr\Http\Message\ResponseInterface
    {
        return $dispatcher->process(new ServerRequest('GET', '/'), new NullHandler());
    }

    public function testStringResultIsHtml(): void
    {
        $response = $this->dispatch($this->dispatcher('stringResult'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertSame('hello', (string) $response->getBody());
    }

    public function testArrayResultIsJson(): void
    {
        $response = $this->dispatch($this->dispatcher('arrayResult'));
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('{"a":1}', (string) $response->getBody());
    }

    public function testNullResultIsEmpty(): void
    {
        $response = $this->dispatch($this->dispatcher('nullResult'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
    }

    public function testRawResponseIsReturnedAsIs(): void
    {
        $response = $this->dispatch($this->dispatcher('rawResult'));
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('yes', $response->getHeaderLine('X-Raw'));
        $this->assertSame('raw', (string) $response->getBody());
    }

    public function testViewRequiresRenderer(): void
    {
        $this->expectException(Ex::class);
        $this->expectExceptionMessage('no renderer is configured');
        $this->dispatch($this->dispatcher('viewResult'));
    }

    public function testViewIsRendered(): void
    {
        $renderer = new class implements RendererInterface {
            public function render(string $template, array $data = []): string
            {
                return $template . ':' . ($data['title'] ?? '');
            }
        };

        $response = $this->dispatch($this->dispatcher('viewResult', $renderer));
        $this->assertSame('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertSame('template:Test', (string) $response->getBody());
    }
}
