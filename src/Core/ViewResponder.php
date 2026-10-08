<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Asset\AssetsInterface;
use Kaly\Asset\AssetView;
use Kaly\Asset\NullAssets;
use Kaly\Auth\AuthView;
use Kaly\Ex;
use Kaly\Http\ContentType;
use Kaly\Http\Csrf\Csrf;
use Kaly\Http\Csrf\CsrfView;
use Kaly\I18n\LocaleResolver;
use Kaly\I18n\LocalizedTranslator;
use Kaly\I18n\TranslatorInterface;
use Kaly\Router\RouterInterface;
use Kaly\Router\UrlView;
use Kaly\View\RendererInterface;
use Kaly\View\View;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/** Renders controller and error views with the current request's capabilities. */
final class ViewResponder
{
    public function __construct(
        private TranslatorInterface $translator,
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private RouterInterface $router,
        private ?RendererInterface $renderer = null,
        private ?AssetsInterface $assets = null,
        private ?Csrf $csrf = null,
        private LocaleResolver $localeResolver = new LocaleResolver(),
    ) {
        $this->assets ??= new NullAssets();
        $this->csrf ??= new Csrf();
    }

    public function respond(View $view, HttpContext $ctx): ResponseInterface
    {
        if ($this->renderer === null) {
            throw new Ex('A View was returned but no renderer is configured: bind a Kaly\View\RendererInterface implementation');
        }
        if (!$ctx->hasLocale()) {
            $ctx->useLocale($this->localeResolver->resolve($ctx->request()));
        }
        $assets = $this->assets;
        $csrf = $this->csrf;
        assert($assets !== null && $csrf !== null);

        $locale = $ctx->locale();
        $environment = new RenderEnvironment(
            i18n: new LocalizedTranslator($this->translator, $locale),
            url: new UrlView($this->router, $locale),
            asset: new AssetView($assets),
            auth: new AuthView($ctx->auth()),
            csrf: new CsrfView($csrf, $ctx->session(...)),
            csp: $ctx->csp(),
        );
        $environment->assertCompatible($view->data);

        return $this->responseFactory
            ->createResponse($view->status)
            ->withHeader('Content-Type', ContentType::HTML)
            ->withBody($this->streamFactory->createStream($this->renderer->render($view->template, $view->data, $environment)));
    }
}
