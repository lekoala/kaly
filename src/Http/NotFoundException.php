<?php

declare(strict_types=1);

namespace Kaly\Http;

use Throwable;

/**
 * The resource does not exist, or the url reaches nothing.
 *
 * The body stays empty: whether a resource exists is the application's
 * business, and a 404 must not tell the client more than that. The message
 * names classes and resolvers and only ever shows up on the debug page.
 */
class NotFoundException extends HttpException
{
    public function __construct(string $message = '', int $code = 404, ?Throwable $previous = null)
    {
        parent::__construct($message ?: 'Not found', $code, [], $previous);
    }

    public function getResponseBody(): string
    {
        return '';
    }
}
