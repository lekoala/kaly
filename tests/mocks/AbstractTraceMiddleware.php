<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Appends its label to the `trace` request attribute, so a controller can
 * report the order in which route middlewares entered.
 */
abstract class AbstractTraceMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'trace';
    public const LABEL = '';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $trace = $request->getAttribute(self::ATTRIBUTE, []);
        assert(is_array($trace));
        $trace[] = static::LABEL;

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $trace));
    }
}
