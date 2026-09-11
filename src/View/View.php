<?php

declare(strict_types=1);

namespace Kaly\View;

/**
 * An explicit controller result: render a template with the given data.
 *
 * The dispatcher turns it into an HTML response using the configured
 * RendererInterface. This keeps template rendering out of the router and out
 * of the controller return type magic.
 */
final class View
{
    /**
     * @param array<string,mixed> $data
     */
    public function __construct(
        public readonly string $template,
        public readonly array $data = [],
    ) {}
}
