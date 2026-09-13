<?php

declare(strict_types=1);

namespace Kaly\Middleware;

use Kaly\Core\HttpContext;
use Psr\Http\Message\ResponseInterface;

/**
 * A response phase of the cycle.
 *
 * Unlike a request middleware (`Request -> next -> Response`), an outgoing
 * middleware is a pure `Response -> Response` transformation. It runs after
 * the whole request pipeline produced a response, whatever its origin — the
 * happy path, a short-circuited request or an error response built by the
 * kernel:
 *
 * ```php
 * final class WebpResponse implements OutgoingMiddlewareInterface
 * {
 *     public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
 *     {
 *         return $response->withHeader('X-Format', 'webp');
 *     }
 * }
 * ```
 *
 * It is executed exactly once. If it throws, the kernel converts the exception
 * into a new error response, it does not replay the band on that response.
 */
interface OutgoingMiddlewareInterface
{
    public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface;
}
