<?php

declare(strict_types=1);

namespace Shop\Controller;

final class ProductController
{
    public function show(string $slug): string
    {
        return 'product:' . $slug;
    }
}
