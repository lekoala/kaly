<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Core\AbstractController;

class ShopController extends AbstractController
{
    public function show(string $slug): string
    {
        return 'shop-' . $slug;
    }

    public function buyPost(string $slug): string
    {
        return 'bought-' . $slug;
    }

    public function health(): string
    {
        return 'ok';
    }

    public function featured(string $tab): string
    {
        return 'featured-' . $tab;
    }
}
