<?php

declare(strict_types=1);

namespace Kaly\View;

interface ExtensionInterface
{
    /**
     * Returns an array of functions as `function name` => `function callback`.
     *
     * @return array<string, callable>
     */
    public function getFunctions(): array;
}
