<?php

declare(strict_types=1);

namespace Kaly\View\Adapter;

use Kaly\View\RendererInterface;
use Kaly\View\TemplateLocatorInterface;
use Twig\Environment;

/**
 * Bridges Twig to Kaly's renderer abstraction.
 *
 * Requires twig/twig (see composer "suggest"). Kaly never abstracts Twig
 * features (extensions, filters, sandbox): configure those on the environment.
 */
final class TwigRenderer implements RendererInterface, TemplateLocatorInterface
{
    public function __construct(
        private readonly Environment $twig,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        return $this->twig->render($template, $data);
    }

    public function has(string $template): bool
    {
        return $this->twig->getLoader()->exists($template);
    }
}
