<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Turns a throwable into an HTTP response.
 *
 * The request is given when there is one, so the response can follow what
 * the client accepts and show what the cycle established.
 */
interface ExceptionHandlerInterface
{
    public function toResponse(Throwable $exception, ?ServerRequestInterface $request = null): ResponseInterface;
}
