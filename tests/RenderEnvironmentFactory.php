<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Asset\AssetsInterface;
use Kaly\Asset\AssetView;
use Kaly\Auth\Authentication;
use Kaly\Auth\AuthView;
use Kaly\Core\RenderEnvironment;
use Kaly\Http\Csp\Csp;
use Kaly\Http\Csrf\Csrf;
use Kaly\Http\Csrf\CsrfView;
use Kaly\Http\Session\ArraySession;
use Kaly\I18n\LocalizedTranslator;
use Kaly\I18n\Translator;
use Kaly\Router\Route;
use Kaly\Router\RouterInterface;
use Kaly\Router\UrlView;
use Psr\Http\Message\ServerRequestInterface;

trait RenderEnvironmentFactory
{
    protected function renderEnvironment(string $locale, ?RouterInterface $router = null): RenderEnvironment
    {
        $translator = (new Translator('en'))->addPath(__DIR__ . '/data/lang');

        return new RenderEnvironment(
            i18n: new LocalizedTranslator($translator, $locale),
            url: new UrlView($router ?? $this->stubRouter(), $locale),
            asset: new AssetView($this->stubAssets()),
            auth: new AuthView(new Authentication()),
            csrf: new CsrfView(new Csrf(), new ArraySession()),
            csp: new Csp(),
        );
    }

    protected function stubAssets(): AssetsInterface
    {
        return new class implements AssetsInterface {
            public function url(string $asset): string
            {
                return '/assets/' . $asset;
            }
        };
    }

    /**
     * A router that echoes the locale it receives: any output proves which
     * locale was captured at build time.
     */
    protected function stubRouter(): RouterInterface
    {
        return new class implements RouterInterface {
            public function match(ServerRequestInterface $request): Route
            {
                throw new \LogicException('The stub router only generates urls');
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
    }
}
