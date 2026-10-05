<?php

declare(strict_types=1);

namespace Kaly\Http\Input;

use Kaly\Validation\ValidationResult;
use Throwable;

/**
 * The request could not be understood: a missing field, a wrong type, or a
 * query string and body that contradict each other.
 *
 * Validation of an already built input is a `ValidationException` (422): this
 * one is about the request data itself.
 */
final class InputException extends ValidationResultException
{
    public function __construct(ValidationResult $validation, ?Throwable $previous = null)
    {
        parent::__construct($validation, 400, $previous);
    }
}
