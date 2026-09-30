<?php

declare(strict_types=1);

namespace Kaly\Http\Exception;

use Throwable;

/**
 * The client is authenticated but not allowed to perform this request.
 *
 * The body stays empty like any other client error: what the user is allowed
 * to do is the application's decision, not the framework's.
 */
class ForbiddenException extends HttpException
{
    public function __construct(string $message = '', int $code = 403, ?Throwable $previous = null)
    {
        parent::__construct($message ?: 'Forbidden', $code, [], $previous);
    }

    public function getResponseBody(): string
    {
        return '';
    }
}
