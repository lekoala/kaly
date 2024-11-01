<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Router\JsonRouteInterface;

class JsonController implements JsonRouteInterface
{
    public function index(): array
    {
        return [];
    }
}
