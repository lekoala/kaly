<?php

declare(strict_types=1);

namespace TestModule\Controller;

final class SlugController
{
    public function __invoke(string $slug): string
    {
        return $slug;
    }
}
