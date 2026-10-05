<?php

declare(strict_types=1);

namespace Kaly\Http\Input;

use Kaly\Http\Exception\HttpException;
use Kaly\Validation\HasValidationResult;
use Kaly\Validation\ValidationResult;
use Throwable;

/**
 * The request was well formed but its values are not acceptable.
 *
 * Thrown when the built input is refused by its `ValidatableInput` rules.
 * Request inputs never validate in their constructor: user-facing constraints
 * belong exclusively to `validate()`.
 */
class ValidationException extends HttpException implements HasValidationResult
{
    public function __construct(ValidationResult $validation, ?Throwable $previous = null)
    {
        parent::__construct(self::firstMessage($validation), 422, [], $previous);
        $this->validation = $validation;
    }

    public function validation(): ValidationResult
    {
        return $this->validation;
    }

    public function getResponseBody(): string
    {
        return self::firstMessage($this->validation);
    }

    private ValidationResult $validation;

    private static function firstMessage(ValidationResult $validation): string
    {
        $violations = $validation->violations();
        if ($violations === []) {
            return '';
        }
        return $violations[0]->fallback;
    }
}
