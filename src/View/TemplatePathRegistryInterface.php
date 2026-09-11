<?php

declare(strict_types=1);

namespace Kaly\View;

/**
 * Optional capability: a renderer that can register template paths, typically
 * one namespace per module.
 *
 * This is not part of RendererInterface because not every renderer is backed by
 * a filesystem (a ChainLoader or an ArrayLoader cannot register paths).
 */
interface TemplatePathRegistryInterface
{
    public function setPath(string $namespace, string $path): void;
}
