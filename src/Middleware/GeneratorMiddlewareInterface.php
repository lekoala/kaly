<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use Generator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface GeneratorMiddlewareInterface
{
    /**
     * @return Generator<int, ServerRequestInterface, ResponseInterface, ResponseInterface>
     */
    public function process(ServerRequestInterface $request): Generator;
}
