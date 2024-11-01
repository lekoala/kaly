<?php

declare(strict_types=1);

namespace Kaly\View;

use Stringable;

/**
 * Provided content should be properly escaped
 */
class ViewData implements Stringable
{
    protected string $contents;

    public function __construct(string $contents)
    {
        $this->contents = $contents;
    }

    public function __toString(): string
    {
        return $this->contents;
    }
}
