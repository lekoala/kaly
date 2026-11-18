<?php

declare(strict_types=1);

namespace Kaly\View\Adapter;

use Closure;
use Kaly\Http\Exception\HttpExceptionInterface;
use Kaly\Tpl\ValueFormatter;
use Kaly\Tpl\ViewEngine;
use Kaly\Tpl\ViewException;
use Kaly\View\RendererInterface;
use Kaly\View\TemplateLocatorInterface;
use Kaly\View\TemplatePathRegistryInterface;

/**
 * Bridges the standalone kaly-tpl engine to Kaly's renderer abstraction.
 *
 * Requires lekoala/kaly-tpl (see composer "suggest").
 */
final class KalyTplRenderer implements RendererInterface, TemplateLocatorInterface, TemplatePathRegistryInterface
{
    /**
     * @param ?Closure(string): ?ValueFormatter $formatterFor Maps the locale of
     *        the render's i18n capability to a formatter for that render only,
     *        so a shared engine formats each request in its own locale. The
     *        engine default is used when null: no callback, or a shared bag
     *        whose i18n exposes no locale.
     */
    public function __construct(
        private readonly ViewEngine $engine,
        private readonly ?Closure $formatterFor = null,
    ) {}

    /**
     * Reserved capabilities are forwarded as per-render shared data so they
     * stay available in partials and layouts; everything else is a page local.
     * A page local sharing a capability's name never replaces it.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $shared
     */
    public function render(string $template, array $data = [], array $shared = []): string
    {
        $formatter = $this->localeFormatter($shared);

        try {
            return (string) $this->engine->render($template, array_diff_key($data, $shared), sharedData: $shared, formatter: $formatter);
        } catch (ViewException $exception) {
            throw $this->unwrapHttpFailure($exception);
        }
    }

    public function has(string $template): bool
    {
        return $this->engine->exists($template);
    }

    public function setPath(string $namespace, string $path): void
    {
        $this->engine->addPath($namespace, $path);
    }

    /**
     * The formatter of this render, from the locale its i18n capability
     * exposes. Like the Twig extension, this bridge depends only on the
     * runtime shape of the capabilities, not on their concrete domain types,
     * so View stays a peer of those domains.
     *
     * @param array<string,mixed> $shared
     */
    private function localeFormatter(array $shared): ?ValueFormatter
    {
        $formatterFor = $this->formatterFor;
        $i18n = $shared['i18n'] ?? null;
        if ($formatterFor === null || !is_object($i18n) || !is_callable([$i18n, 'locale'])) {
            return null;
        }

        $locale = $i18n->locale();

        return is_string($locale) ? $formatterFor($locale) : null;
    }

    /**
     * kaly-tpl wraps every application exception in a ViewException; an HTTP
     * failure thrown by a template is a deliberate client-facing outcome, so
     * surface the original with the status it chose instead of letting a
     * deliberate 404 answer as a render error (500).
     */
    private function unwrapHttpFailure(ViewException $exception): \Throwable
    {
        for ($previous = $exception->getPrevious(); $previous !== null; $previous = $previous->getPrevious()) {
            if ($previous instanceof HttpExceptionInterface) {
                return $previous;
            }
        }

        return $exception;
    }
}
