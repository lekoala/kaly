<?php

declare(strict_types=1);

namespace Kaly\Http\Input;

use Kaly\Http\Exception\HttpException;
use Kaly\Validation\HasValidationResult;
use Kaly\Validation\ValidationResult;
use Throwable;

/**
 * The request could not be understood: a missing field, a wrong type, or a
 * query string and body that contradict each other.
 *
 * Validation of an already built input is a `ValidationException` (422): this
 * one is about the request data itself.
 */
class InputException extends HttpException implements HasValidationResult
{
    public function __construct(ValidationResult $validation, ?Throwable $previous = null)
    {
        parent::__construct(self::firstMessage($validation), 400, [], $previous);
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
