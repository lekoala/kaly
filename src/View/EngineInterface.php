<?php

declare(strict_types=1);

namespace Kaly\View;

interface EngineInterface
{
    /**
     * Render a template by name
     *
     * Template names can be part of a path using :: notation (eg: admin::sometemplate)
     *
     * @param string $name
     * @param array<string,mixed> $data
     * @return string
     */
    public function render(string $name, array $data = []): string;

    /**
     * @param string $name
     * @return bool
     */
    public function has(string $name): bool;
}
