<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\HttpContext;
use Kaly\Core\RequestDispatcher;
use Kaly\Core\RoutingHandler;
use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use Kaly\Ex;
use Kaly\Http\RedirectException;
use Kaly\I18n\LocaleResolver;
use Kaly\I18n\LocalizedTranslator;
use Kaly\I18n\Translator;
use Kaly\Router\Route;
use Kaly\Router\RouterInterface;
use Kaly\Tests\Mocks\DispatcherController;
use Kaly\View\RendererInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class RequestDispatcherTest extends TestCase
{
    private function dispatcher(
        string $action,
        ?RendererInterface $renderer = null,
        ?string $routeLocale = null,
        ?LocaleResolver $localeResolver = null,
    ): RoutingHandler {
        $router = new class($action, $routeLocale) implements RouterInterface {
            public function __construct(
                private string $action,
                private ?string $routeLocale = null,
            ) {}

            public function match(ServerRequestInterface $request): Route
            {
                return new Route(controller: DispatcherController::class, action: $this->action, locale: $this->routeLocale);
            }

            public function url(string $name, array $params = [], ?string $locale = null): string
            {
                return ($locale ?? '-') . ':' . $name;
            }

            public function urlFor(string|array $handler, array $params = [], ?string $locale = null): string
            {
                return '';
            }
        };

        $container = new Container(new Definitions());
        $injector = new Injector($container);
        $factory = new Psr17Factory();

        $translator = (new Translator('en'))->addPath(__DIR__ . '/data/lang');

        $dispatcher = new RequestDispatcher($injector, $translator, $factory, $factory, $renderer);

        // The dispatcher only runs behind the routing step
        return new RoutingHandler($router, $localeResolver ?? new LocaleResolver('en', ['en', 'fr']), $dispatcher);
    }

    private function dispatch(RoutingHandler $handler, ?string $acceptLanguage = null): ResponseInterface
    {
        $request = new ServerRequest('GET', '/');
        if ($acceptLanguage !== null) {
            $request = $request->withHeader('Accept-Language', $acceptLanguage);
        }
        $ctx = new HttpContext($request);
        return $handler->handle($ctx->bind($request));
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

    public function testNullResultIsAProgrammingError(): void
    {
        $this->expectException(Ex::class);
        $this->expectExceptionMessage('returned null');
        $this->dispatch($this->dispatcher('nullResult'));
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
                return $i18n->locale() . ':' . $i18n->translate('global.test');
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
                return $i18n->locale();
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

    public function testRenderReceivesAUrlGeneratorBoundToTheRequestLocale(): void
    {
        $renderer = new class implements RendererInterface {
            public function render(string $template, array $data = []): string
            {
                $url = $data[RequestDispatcher::VAR_URL];
                assert($url instanceof \Closure);
                return $url('shop:product', ['slug' => 'velo']);
            }
        };

        // The stub router echoes the locale it receives
        $dispatcher = $this->dispatcher('viewResult', $renderer, 'fr');
        $this->assertSame('fr:shop:product', (string) $this->dispatch($dispatcher)->getBody());

        $dispatcher = $this->dispatcher('viewResult', $renderer);
        $this->assertSame('en:shop:product', (string) $this->dispatch($dispatcher)->getBody());
    }

    public function testUrlIsReservedAndOverridesViewData(): void
    {
        $renderer = new class implements RendererInterface {
            public function render(string $template, array $data = []): string
            {
                return get_debug_type($data[RequestDispatcher::VAR_URL]);
            }
        };

        $response = $this->dispatch($this->dispatcher('viewResultWithUrl', $renderer));
        $this->assertSame('Closure', (string) $response->getBody());
    }

    public function testJsonResultCarriesStatusAndHeaders(): void
    {
        $response = $this->dispatch($this->dispatcher('jsonResponseResult'));
        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));
        $this->assertSame('yes', $response->getHeaderLine('X-Test'));
        $this->assertSame('{"a":1}', (string) $response->getBody());
    }

    public function testViewResultWithStatusAnswersWithThatStatus(): void
    {
        $renderer = new class implements RendererInterface {
            public function render(string $template, array $data = []): string
            {
                return $template . ':' . ($data['title'] ?? '');
            }
        };

        $response = $this->dispatch($this->dispatcher('viewResultWithStatus', $renderer));
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('template:Test', (string) $response->getBody());
    }

    public function testRedirectHelperRedirectsToTheNamedRoute(): void
    {
        try {
            $this->dispatch($this->dispatcher('redirectResult'));
            $this->fail('A redirect was expected');
        } catch (RedirectException $e) {
            // The stub router echoes the locale it receives
            $this->assertSame(303, $e->getCode());
            $this->assertSame('en:shop:product', $e->getUrl());
        }
    }
}
