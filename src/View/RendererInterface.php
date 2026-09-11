<?php

declare(strict_types=1);

namespace Kaly\View;

/**
 * The minimal contract Kaly needs from a template renderer.
 *
 * Kaly only standardizes a template identifier and a data bag: it must be
 * possible to render any engine (Twig, Kaly Tpl, ...) behind this interface.
 */
interface RendererInterface
{
    /**
     * Render a template by its logical name.
     *
     * Template names may use a namespace notation (eg: admin::template or
     * @admin/template) that the renderer is free to resolve its own way.
     *
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = []): string;
}
