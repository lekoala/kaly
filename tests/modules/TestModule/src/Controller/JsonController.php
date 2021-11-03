<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Interfaces\JsonRouteInterface;
use Psr\Http\Message\ServerRequestInterface;

class JsonController implements JsonRouteInterface
{
    public function index(ServerRequestInterface $request)
    {
        return [];
    }
}
