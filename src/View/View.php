<?php

declare(strict_types=1);

namespace Kaly\View;

/**
 * An explicit controller result: render a template with the given data.
 *
 * ViewResponder turns it into an HTML response using the configured
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
        public readonly int $status = 200,
    ) {}

    /**
     * @param array<string,mixed> $data
     */
    public static function of(string $template, array $data = [], int $status = 200): self
    {
        return new self($template, $data, $status);
    }

    public function withStatus(int $status): self
    {
        return new self($this->template, $this->data, $status);
    }
}
