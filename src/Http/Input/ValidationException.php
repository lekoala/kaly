<?php

declare(strict_types=1);

namespace Kaly\Http\Input;

use Kaly\Http\Exception\HttpException;
use Throwable;

/**
 * The request was well formed but its values are not acceptable.
 *
 * Thrown from the constructor of a `RequestInput`, or from its `validate()`
 * when it implements `ValidatableInput`.
 */
class ValidationException extends HttpException
{
    public function __construct(string $message = '', int $code = 422, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, [], $previous);
    }
}
