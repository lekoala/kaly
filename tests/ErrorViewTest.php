<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Asset\Assets;
use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\ErrorViewInterface;
use Kaly\Core\HttpContext;
use Kaly\Di\Definitions;
use Kaly\Http\ErrorPageInterface;
use Kaly\Http\Exception\HttpException;
use Kaly\Http\Exception\NotFoundException;
use Kaly\Http\ExceptionHandler;
use Kaly\Router\TrailingSlash;
use Kaly\Tpl\ViewEngine;
use Kaly\View\Adapter\KalyTplRenderer;
use Kaly\View\RendererInterface;
use Kaly\View\View;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use Throwable;

final class ErrorViewTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    public function testRouting404RendersWithRequestCapabilitiesAndNegotiatedLocale(): void
    {
        $view = $this->errorView();
        $app = $this->app($view);
        $response = $app->handle(
            (new ServerRequest('GET', '/missing/'))
                ->withHeader('Accept', 'text/html')
                ->withHeader('Accept-Language', 'fr'),
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('text/html; charset=utf-8', $response->getHeaderLine('Content-Type'));
        $this->assertSame('fr|auth:anonymous|csrf:_csrf|csp:yes|asset:yes|url:yes|error:404', trim((string) $response->getBody()));
        $this->assertSame(1, $view->calls);
        $this->assertFalse($response->hasHeader('Set-Cookie'));
    }

    public function testJsonDoesNotInvokeErrorView(): void
    {
        $view = $this->errorView();
        $response = $this->app($view)->handle((new ServerRequest('GET', '/missing/'))->withHeader('Accept', 'application/json'));
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(ExceptionHandler::PROBLEM_JSON, $response->getHeaderLine('Content-Type'));
        $this->assertSame(0, $view->calls);
    }

    public function testIncoming500CanRenderBeforeTheRouterRuns(): void
    {
        $view = $this->errorView();
        $app = $this->app($view);
        $app->middleware()->incoming(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                throw new RuntimeException('private failure');
            }
        });
        $response = $app->handle(new ServerRequest('GET', '/'));
        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringContainsString('url:yes|error:500', (string) $response->getBody());
        $this->assertStringNotContainsString('private failure', (string) $response->getBody());
    }

    public function testConsecutiveErrorsUseIndependentLocales(): void
    {
        $app = $this->app($this->errorView());
        foreach (['fr', 'en'] as $locale) {
            $response = $app->handle((new ServerRequest('GET', '/missing/'))->withHeader('Accept-Language', $locale));
            $this->assertStringStartsWith($locale . '|', (string) $response->getBody());
        }
    }

    public function testDebugDoesNotInvokeErrorView(): void
    {
        $view = $this->errorView();
        $response = $this->app($view, debug: true)->handle(new ServerRequest('GET', '/missing/'));
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(0, $view->calls);
    }

    public function testDeclinedViewKeepsStandard404(): void
    {
        $view = $this->errorView(null);
        $response = $this->app($view)->handle(new ServerRequest('GET', '/missing/'));
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', (string) $response->getBody());
        $this->assertSame(1, $view->calls);
    }

    public function testBrokenErrorTemplateFallsBackWithoutRecursion(): void
    {
        $view = $this->errorView('missing-template');
        $response = $this->app($view)->handle(new ServerRequest('GET', '/missing/'));
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', (string) $response->getBody());
        $this->assertSame(1, $view->calls);
    }

    public function testExplicitBodiesAndFormatsTakePriorityAndHeadersSurvive(): void
    {
        $page = new class implements ErrorPageInterface {
            public int $calls = 0;

            public function html(Throwable $exception, ServerRequestInterface $request, int $status): string
            {
                $this->calls++;
                return 'custom error';
            }
        };
        $factory = new Psr17Factory();
        $handler = new ExceptionHandler($factory, $factory, errorPage: $page);
        $request = new ServerRequest('GET', '/');
        $explicit = new class extends HttpException {
            public function __construct()
            {
                parent::__construct('public failure', 403, ['Retry-After' => '30']);
            }
        };
        $this->assertSame('public failure', (string) $handler->toResponse($explicit, $request)->getBody());
        $formatted = new class extends HttpException {
            public function __construct()
            {
                parent::__construct('explicit html', 404, ['Content-Type' => 'text/html']);
            }
        };
        $this->assertSame('explicit html', (string) $handler->toResponse($formatted, $request)->getBody());
        $this->assertSame(0, $page->calls);
        $silent = new class extends HttpException {
            public function __construct()
            {
                parent::__construct('', 503, ['Retry-After' => '30']);
            }
        };
        $response = $handler->toResponse($silent, $request);
        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('30', $response->getHeaderLine('Retry-After'));
        $this->assertSame('custom error', (string) $response->getBody());
        $this->assertSame('custom error', (string) $handler->toResponse(new RuntimeException('private'), $request)->getBody());
        $this->assertSame('Not Found', (string) $handler->toResponse(new NotFoundException())->getBody());
    }

    private function app(ErrorViewInterface $view, bool $debug = false): App
    {
        return App::create(__DIR__)
            ->routing(TrailingSlash::Add, true)
            ->debug($debug)
            ->configure(static function (Definitions $di) use ($view): void {
                $di->set(ErrorViewInterface::class, $view);
                $di->parameter(Assets::class, 'version', 'test');
                $di->rebind(RendererInterface::class, new KalyTplRenderer(new ViewEngine(__DIR__ . '/adapters/kaly-tpl/errors')));
            })
            ->boot();
    }

    private function errorView(?string $template = 'error'): CountingErrorView
    {
        return new CountingErrorView($template);
    }
}

final class CountingErrorView implements ErrorViewInterface
{
    public int $calls = 0;

    public function __construct(
        private ?string $template,
    ) {}

    public function view(Throwable $exception, HttpContext $ctx, int $status): ?View
    {
        $this->calls++;
        return $this->template === null ? null : View::of($this->template, ['status' => $status]);
    }
}
