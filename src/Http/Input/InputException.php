<?php

declare(strict_types=1);

namespace Kaly\Http\Input;

use Kaly\Http\Exception\HttpException;
use Throwable;

/**
 * The request could not be understood: a missing field, a wrong type, or a
 * query string and body that contradict each other.
 *
 * Validation of an already built input is a `ValidationException` (422): this
 * one is about the request data itself.
 */
class InputException extends HttpException
{
    public function __construct(string $message = '', int $code = 400, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, [], $previous);
    }
}
