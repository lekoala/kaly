<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use Kaly\Core\HttpContext;
use Kaly\Middleware\OutgoingMiddlewareInterface;
use Psr\Http\Message\ResponseInterface;

class TestOutgoing implements OutgoingMiddlewareInterface
{
    public const HEADER = 'X-Test-Outgoing';
    public const VALUE = 'done';

    public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
    {
        return $response->withHeader(self::HEADER, self::VALUE);
    }
}
