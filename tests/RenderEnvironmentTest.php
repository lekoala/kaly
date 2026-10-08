<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Fiber;
use Kaly\Asset\AssetView;
use Kaly\Auth\Authentication;
use Kaly\Auth\AuthView;
use Kaly\Core\HttpContext;
use Kaly\Core\RenderEnvironment;
use Kaly\Core\ViewResponder;
use Kaly\Http\Csp\Csp;
use Kaly\Http\Csrf\Csrf;
use Kaly\Http\Csrf\CsrfView;
use Kaly\Http\Session\ArraySession;
use Kaly\Http\Session\SessionInterface;
use Kaly\Http\Session\SessionProviderInterface;
use Kaly\I18n\LocalizedTranslator;
use Kaly\I18n\Translator;
use Kaly\Router\UrlView;
use Kaly\View\RenderEnvironmentInterface;
use Kaly\View\RendererInterface;
use Kaly\View\View;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The environment itself: reserved names, lazy capabilities, captured locale
 * and the absence of any ambient request state.
 */
class RenderEnvironmentTest extends TestCase
{
    use RenderEnvironmentFactory;

    public function testVariablesExposeTheSixReservedNames(): void
    {
        $this->assertSame(RenderEnvironment::RESERVED, array_keys($this->renderEnvironment('fr')->variables()));
    }

    public function testUrlViewCapturesTheLocaleOfTheRender(): void
    {
        $renderer = new class implements RendererInterface {
            public ?UrlView $url = null;

            public function render(string $template, array $data = [], ?RenderEnvironmentInterface $environment = null): string
            {
                $url = $environment?->variables()['url'] ?? null;
                $this->url = $url instanceof UrlView ? $url : null;

                return '';
            }
        };

        $factory = new Psr17Factory();
        $responder = new ViewResponder(new Translator('en'), $factory, $factory, $this->stubRouter(), $renderer);

        $ctx = new HttpContext(new ServerRequest('GET', '/'));
        $ctx->useLocale('fr');
        $responder->respond(View::of('x'), $ctx);

        // The render is over; a later locale change must not rewrite its urls
        $ctx->useLocale('en');

        $url = $renderer->url;
        assert($url instanceof UrlView);
        $this->assertSame('fr:home', $url('home'));
    }

    public function testUrlCapabilityIsAvailableWithoutRouting(): void
    {
        $renderer = new class implements RendererInterface {
            public ?UrlView $url = null;

            public function render(string $template, array $data = [], ?RenderEnvironmentInterface $environment = null): string
            {
                $url = $environment?->variables()['url'] ?? null;
                $this->url = $url instanceof UrlView ? $url : null;

                return '';
            }
        };

        $factory = new Psr17Factory();
        $responder = new ViewResponder(new Translator('en'), $factory, $factory, $this->stubRouter(), $renderer);

        // An error view is rendered before the request is routed: url still works
        $ctx = new HttpContext(new ServerRequest('GET', '/'));
        $responder->respond(View::of('errors/500'), $ctx);

        $url = $renderer->url;
        assert($url instanceof UrlView);
        $this->assertSame('en:home', $url('home'));
    }

    public function testBuildingTheEnvironmentDoesNotStartTheSession(): void
    {
        $provider = new class implements SessionProviderInterface {
            public int $createCalls = 0;

            public function create(ServerRequestInterface $request): SessionInterface
            {
                $this->createCalls++;

                return new ArraySession();
            }

            public function commit(
                SessionInterface $session,
                ServerRequestInterface $request,
                ResponseInterface $response,
            ): ResponseInterface {
                return $response;
            }
        };

        $ctx = new HttpContext(new ServerRequest('GET', '/'), $provider);

        $environment = new RenderEnvironment(
            i18n: new LocalizedTranslator(new Translator('en'), 'en'),
            url: new UrlView($this->stubRouter(), 'en'),
            asset: new AssetView($this->stubAssets()),
            auth: new AuthView(new Authentication()),
            csrf: new CsrfView(new Csrf(), $ctx->session(...)),
            csp: new Csp(),
        );

        $this->assertSame(0, $provider->createCalls);

        $environment->csrf->token();

        $this->assertSame(1, $provider->createCalls);
    }

    public function testEnvironmentsAreNeverSharedAcrossRenders(): void
    {
        $renderer = new class implements RendererInterface {
            /** @var list<string> */
            public array $seen = [];

            public function render(string $template, array $data = [], ?RenderEnvironmentInterface $environment = null): string
            {
                $i18n = $environment?->variables()['i18n'] ?? null;
                $this->seen[] = $i18n instanceof LocalizedTranslator ? $i18n->locale() : 'none';
                if ($template === 'suspend') {
                    Fiber::suspend();
                }

                return '';
            }
        };

        $fiber = new Fiber(fn(): string => $renderer->render('suspend', [], $this->renderEnvironment('fr')));
        $fiber->start();

        $renderer->render('plain', [], $this->renderEnvironment('en'));

        $fiber->resume();

        $this->assertSame(['fr', 'en'], $renderer->seen);
    }
}
