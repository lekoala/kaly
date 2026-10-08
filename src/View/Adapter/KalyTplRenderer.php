<?php

declare(strict_types=1);

namespace Kaly\View\Adapter;

use Kaly\Tpl\ViewEngine;
use Kaly\View\RenderEnvironmentInterface;
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
    public function __construct(
        private readonly ViewEngine $engine,
    ) {}

    /**
     * Reserved capabilities are forwarded as per-render shared data so they
     * stay available in partials and layouts; everything else is a page local.
     *
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = [], ?RenderEnvironmentInterface $environment = null): string
    {
        if ($environment !== null) {
            $environment->assertCompatible($data);
        }

        return (string) $this->engine->render($template, $data, sharedData: $environment?->variables() ?? []);
    }

    public function has(string $template): bool
    {
        return $this->engine->exists($template);
    }

    public function setPath(string $namespace, string $path): void
    {
        $this->engine->addPath($namespace, $path);
    }
}
