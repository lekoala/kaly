<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Turns a throwable into an HTTP response.
 *
 * The core no longer binds a concrete PSR-7 implementation: the default
 * implementation builds responses from PSR-17 factories provided by the app.
 */
interface ExceptionHandlerInterface
{
    public function toResponse(Throwable $exception): ResponseInterface;
}
