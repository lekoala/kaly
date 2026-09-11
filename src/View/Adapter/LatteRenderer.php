<?php

declare(strict_types=1);

namespace Kaly\View\Adapter;

use Kaly\View\RendererInterface;
use Kaly\View\TemplateLocatorInterface;
use Latte\Engine;

/**
 * Bridges Latte to Kaly's renderer abstraction.
 *
 * Requires latte/latte (see composer "suggest"). Kaly never abstracts Latte
 * features (filters, extensions, sandbox): configure those on the engine.
 */
final class LatteRenderer implements RendererInterface, TemplateLocatorInterface
{
    public function __construct(
        private readonly Engine $engine,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        return $this->engine->renderToString($template, $data);
    }

    public function has(string $template): bool
    {
        try {
            $this->engine->getLoader()->getContent($template);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
