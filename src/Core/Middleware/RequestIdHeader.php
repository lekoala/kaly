<?php

declare(strict_types=1);

namespace Kaly\Core\Middleware;

use Kaly\Core\HttpContext;
use Psr\Http\Message\ResponseInterface;

/**
 * Exposes `HttpContext::requestId()` as an `X-Request-Id` response header.
 *
 * Register it as an `always` outgoing middleware so error responses carry
 * the id too, letting a user report the exact cycle found in the logs:
 *
 * ```php
 * $app->middleware()->outgoing(RequestIdHeader::class, always: true);
 * ```
 */
final readonly class RequestIdHeader implements OutgoingInterface
{
    public const HEADER = 'X-Request-Id';

    public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
    {
        return $response->withHeader(self::HEADER, $ctx->requestId());
    }
}
