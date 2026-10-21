<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use Kaly\Core\HttpContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * An incoming middleware that establishes the locale before routing.
 */
final class ImposeLocaleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly string $locale,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        HttpContext::from($request)->useLocale($this->locale);

        return $handler->handle($request);
    }
}
