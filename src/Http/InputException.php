<?php

declare(strict_types=1);

namespace Kaly\Http;

use Kaly\Core\Ex;
use Throwable;

/**
 * The route exists but its input cannot be built: a value that cannot be
 * coerced to the declared type, a missing required property, or the same key
 * sent with contradicting values in the query and in the body.
 *
 * This is distinct from a 404, where the route itself does not match, and from
 * a ValidationException, where the input is well typed and still refused.
 */
class InputException extends Ex implements HttpExceptionInterface
{
    public function __construct(string $message = '', int $code = 400, ?Throwable $previous = null)
    {
        if (!$message) {
            $message = 'Bad request';
        }
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
