<?php

declare(strict_types=1);

namespace Kaly\Http\Input;

use Kaly\Validation\ValidationResult;
use Throwable;

/**
 * The request was well formed but its values are not acceptable.
 *
 * Thrown when the built input is refused by its `ValidatableInput` rules.
 * Request inputs never validate in their constructor: user-facing constraints
 * belong exclusively to `validate()`.
 */
final class ValidationException extends ValidationResultException
{
    public function __construct(ValidationResult $validation, ?Throwable $previous = null)
    {
        parent::__construct($validation, 422, $previous);
    }
}
