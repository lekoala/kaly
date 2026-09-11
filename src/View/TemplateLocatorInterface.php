<?php

declare(strict_types=1);

namespace Kaly\View;

/**
 * Optional capability: a renderer that can tell whether a template exists.
 *
 * Not every renderer is backed by a filesystem, so this is deliberately kept
 * out of RendererInterface.
 */
interface TemplateLocatorInterface
{
    public function has(string $template): bool;
}
