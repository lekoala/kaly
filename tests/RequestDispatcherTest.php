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
use Kaly\Text\LocaleResolver;
use Kaly\Text\LocalizedTranslator;
use Kaly\Text\Translator;
use Kaly\View\RendererInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

class RequestDispatcherTest extends TestCase
{
    private function dispatcher(
        string $action,
        ?RendererInterface $renderer = null,
        ?string $routeLocale = null,
        ?LocaleResolver $localeResolver = null,
    ): RequestDispatcher {
        $router = new class($action, $routeLocale) implements RouterInterface {
            public function __construct(
                private string $action,
                private ?string $routeLocale = null,
            ) {}

            public function match(ServerRequestInterface $request): Route
            {
                $route = new Route();
                $route->controller = DispatcherController::class;
                $route->action = $this->action;
                $route->locale = $this->routeLocale;
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

        $translator = (new Translator('en'))->addPath(__DIR__ . '/data/lang');

        return new RequestDispatcher(
            $router,
            $injector,
            $translator,
            $localeResolver ?? new LocaleResolver('en', ['en', 'fr']),
            $factory,
            $factory,
            $renderer,
        );
    }

    private function dispatch(RequestDispatcher $dispatcher, ?string $acceptLanguage = null): \Psr\Http\Message\ResponseInterface
    {
        $request = new ServerRequest('GET', '/');
        if ($acceptLanguage !== null) {
            $request = $request->withHeader('Accept-Language', $acceptLanguage);
        }
        return $dispatcher->process($request, new NullHandler());
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

    public function testRouteAttributeIsAvailableToController(): void
    {
        $response = $this->dispatch($this->dispatcher('routeResult'));
        $body = json_decode((string) $response->getBody(), true);

        $this->assertIsArray($body);
        $this->assertIsArray($body['route']);
        $this->assertSame(DispatcherController::class, $body['route']['controller']);
        $this->assertSame('routeResult', $body['route']['action']);
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

    public function testRenderReceivesALocalizedTranslator(): void
    {
        $renderer = new class implements RendererInterface {
            public function render(string $template, array $data = []): string
            {
                $i18n = $data[RequestDispatcher::VAR_I18N];
                assert($i18n instanceof LocalizedTranslator);
                return $i18n->getLocale() . ':' . $i18n->translate('global.test');
            }
        };

        // Two successive renders on the same dispatcher must not leak a locale
        $dispatcher = $this->dispatcher('viewResult', $renderer);
        $this->assertSame('fr:Message de test', (string) $this->dispatch($dispatcher, 'fr')->getBody());
        $this->assertSame('en:Test message', (string) $this->dispatch($dispatcher, 'en')->getBody());
        // No preference at all falls back to the default locale
        $this->assertSame('en:Test message', (string) $this->dispatch($dispatcher)->getBody());
    }

    public function testRouteLocaleWinsOverHeaders(): void
    {
        $renderer = new class implements RendererInterface {
            public function render(string $template, array $data = []): string
            {
                $i18n = $data[RequestDispatcher::VAR_I18N];
                assert($i18n instanceof LocalizedTranslator);
                return $i18n->getLocale();
            }
        };

        $dispatcher = $this->dispatcher('viewResult', $renderer, 'fr');
        $this->assertSame('fr', (string) $this->dispatch($dispatcher, 'en')->getBody());
    }

    public function testResolvedLocaleIsExposedAsARequestAttribute(): void
    {
        $response = $this->dispatch($this->dispatcher('localeResult'), 'fr');
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame('fr', $body['locale']);
    }

    public function testI18nIsReservedAndOverridesViewData(): void
    {
        $renderer = new class implements RendererInterface {
            public function render(string $template, array $data = []): string
            {
                return get_debug_type($data[RequestDispatcher::VAR_I18N]);
            }
        };

        $response = $this->dispatch($this->dispatcher('viewResultWithI18n', $renderer));
        $this->assertSame(LocalizedTranslator::class, (string) $response->getBody());
    }
}
