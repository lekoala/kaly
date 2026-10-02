<?php

declare(strict_types=1);

namespace Kaly\View\Adapter;

use Kaly\Ex;
use Kaly\View\RendererInterface;
use Kaly\View\TemplateLocatorInterface;
use Kaly\View\TemplatePathRegistryInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Bridges Twig to Kaly's renderer abstraction.
 *
 * Requires twig/twig (see composer "suggest"). Kaly never abstracts Twig
 * features (extensions, filters, sandbox): configure those on the environment.
 */
final class TwigRenderer implements RendererInterface, TemplateLocatorInterface, TemplatePathRegistryInterface
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

    public function setPath(string $namespace, string $path): void
    {
        $loader = $this->twig->getLoader();
        if (!$loader instanceof FilesystemLoader) {
            throw new Ex('TwigRenderer registers paths on a FilesystemLoader only');
        }
        $loader->addPath($path, $namespace);
    }
}
