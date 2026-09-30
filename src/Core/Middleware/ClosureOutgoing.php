<?php

declare(strict_types=1);

namespace Kaly\Core\Middleware;

use Closure;
use Kaly\Core\HttpContext;
use LogicException;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal An outgoing middleware written as a closure:
 *
 * ```php
 * $app->middleware()->outgoing(
 *     fn(ResponseInterface $response, HttpContext $ctx) => $response->withHeader('X-Frame-Options', 'DENY'),
 *     always: true,
 * );
 * ```
 */
final class ClosureOutgoing implements OutgoingInterface
{
    /**
     * @param Closure(ResponseInterface, HttpContext): ResponseInterface $closure
     */
    public function __construct(
        private Closure $closure,
    ) {}

    public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
    {
        $result = ($this->closure)($response, $ctx);
        if (!$result instanceof ResponseInterface) {
            throw new LogicException('An outgoing closure must return a ResponseInterface, got ' . get_debug_type($result));
        }
        return $result;
    }
}
