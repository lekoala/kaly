<?php

declare(strict_types=1);

namespace Kaly\Http;

use Kaly\Core\Ex;
use Throwable;

/**
 * Validation error that should show as an alert or a form error
 * Nested error will be concatenated
 * It would result in a "fail" status in json
 */
class ValidationException extends Ex implements HttpExceptionInterface
{
    public function __construct(string $message, int $code = 403, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getResponseHeaders(): array
    {
        return [];
    }

    public function getResponseBody(): string
    {
        return $this->getMessage();
    }
}
