<?php

declare(strict_types=1);

namespace Kaly\Http;

use Kaly\Core\Ex;
use Throwable;

/**
 * The input is well typed and still refused by a business rule.
 *
 * This is 422, distinct from an InputException (400) where the data could not
 * be represented by the declared type at all.
 */
class ValidationException extends Ex implements HttpExceptionInterface
{
    public function __construct(string $message, int $code = 422, ?Throwable $previous = null)
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
