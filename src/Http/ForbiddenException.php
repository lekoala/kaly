<?php

declare(strict_types=1);

namespace Kaly\Http;

use Kaly\Core\Ex;
use Throwable;

/**
 * The client is authenticated but not allowed to perform this request.
 *
 * The body stays empty like any other client error: what the user is allowed
 * to do is the application's decision, not the framework's.
 */
class ForbiddenException extends Ex implements HttpExceptionInterface
{
    public function __construct(string $message = '', int $code = 403, ?Throwable $previous = null)
    {
        if (!$message) {
            $message = 'Forbidden';
        }
        parent::__construct($message, $code, $previous);
    }

    public function getResponseHeaders(): array
    {
        return [];
    }

    public function getResponseBody(): string
    {
        return '';
    }
}
