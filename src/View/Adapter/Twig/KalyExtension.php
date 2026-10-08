<?php

declare(strict_types=1);

namespace Kaly\View\Adapter\Twig;

use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Idiomatic Twig exposure for Kaly's reserved render capabilities.
 *
 * The extension holds no state of its own: every callable declares
 * `needs_context` and reads the capability from the context of the current
 * render, so a shared Twig environment never carries one request's locale or
 * user. It deliberately depends only on the runtime shape of the capabilities,
 * not on their concrete domain types, so View stays a peer of those domains.
 *
 * Installing it is a decision of {@see \Kaly\View\Adapter\TwigRenderer}.
 */
final class KalyExtension extends AbstractExtension
{
    /**
     * @return list<TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('trans', $this->trans(...), ['needs_context' => true]),
        ];
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('url', $this->url(...), ['needs_context' => true]),
            new TwigFunction('asset', $this->asset(...), ['needs_context' => true]),
        ];
    }

    /**
     * Explicit message identifiers only: a literal is never guessed into a key.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $parameters
     */
    public function trans(array $context, string $message, array $parameters = [], ?string $domain = null): string
    {
        $i18n = $context['i18n'] ?? null;
        if (!is_object($i18n) || !is_callable([$i18n, 'translate'])) {
            throw new RuntimeError("The trans filter requires the 'i18n' render capability");
        }

        $translated = $i18n->translate($message, $parameters, $domain);

        return is_string($translated) ? $translated : '';
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $params
     */
    public function url(array $context, string $name, array $params = []): string
    {
        $url = $context['url'] ?? null;
        if (!is_callable($url)) {
            throw new RuntimeError("The url() function requires the 'url' render capability");
        }

        $generated = $url($name, $params);

        return is_string($generated) ? $generated : '';
    }

    /**
     * @param array<string,mixed> $context
     */
    public function asset(array $context, string $asset): string
    {
        $assets = $context['asset'] ?? null;
        if (!is_callable($assets)) {
            throw new RuntimeError("The asset() function requires the 'asset' render capability");
        }

        $url = $assets($asset);

        return is_string($url) ? $url : '';
    }
}
