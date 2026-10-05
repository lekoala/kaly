<?php

declare(strict_types=1);

namespace Kaly\View\Adapter;

use Kaly\Tpl\ViewEngine;
use Kaly\View\RendererInterface;
use Kaly\View\RenderVariables;
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
     * Framework capabilities are forwarded as per-render shared data so they
     * stay available in partials and layouts; everything else is a page local.
     *
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $shared = [];
        foreach (RenderVariables::SHARED as $name) {
            if (array_key_exists($name, $data)) {
                $shared[$name] = $data[$name];
                unset($data[$name]);
            }
        }

        return (string) $this->engine->render($template, $data, sharedData: $shared);
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
