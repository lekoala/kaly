<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Http\ErrorPageInterface;
use Kaly\I18n\LocaleResolver;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/** @internal Bridges application error views to the HTTP handler's HTML fallback. */
final class ViewErrorPage implements ErrorPageInterface
{
    public function __construct(
        private ErrorViewInterface $errorView,
        private ViewResponder $views,
        private LocaleResolver $localeResolver,
    ) {}

    public function html(Throwable $exception, ServerRequestInterface $request, int $status): ?string
    {
        $ctx = HttpContext::tryFrom($request);
        if ($ctx === null) {
            return null;
        }
        if (!$ctx->hasLocale()) {
            $ctx->useLocale($this->localeResolver->resolve($ctx->request()));
        }
        $view = $this->errorView->view($exception, $ctx, $status);
        return $view === null ? null : (string) $this->views->respond($view->withStatus($status), $ctx)->getBody();
    }
}
