<?php

declare(strict_types=1);

namespace Kaly\View;

/**
 * The minimal contract Kaly needs from a template renderer.
 *
 * Kaly standardizes a template identifier, an application data bag and the
 * reserved capabilities of the render. Data and environment travel separately:
 * a renderer exposes the capabilities its engine understands, without ever
 * letting page data replace them.
 */
interface RendererInterface
{
    /**
     * Render a template by its logical name.
     *
     * Template names may use a namespace notation (eg: admin::template or
     * @admin/template) that the renderer is free to resolve its own way.
     *
     * Passing a null environment means the render is standalone: no capability
     * is injected and the reserved names are not enforced.
     *
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = [], ?RenderEnvironmentInterface $environment = null): string;
}
