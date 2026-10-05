<?php

declare(strict_types=1);

namespace Kaly\Http\Input;

use Kaly\Http\Exception\HttpException;
use Kaly\Validation\HasValidationResult;
use Kaly\Validation\ValidationResult;
use LogicException;
use Throwable;

/**
 * @internal
 *
 * A request failure carrying its structured validation outcome.
 *
 * Only the two public final subclasses are instantiated or caught:
 * `InputException` for data the type cannot represent (400) and
 * `ValidationException` for well typed but refused values (422). The status
 * belongs to each subclass and is never configurable. Failing with an empty
 * result is a programming error: an empty `ValidationResult` is perfectly
 * valid, turning it into an exception is not.
 */
abstract class ValidationResultException extends HttpException implements HasValidationResult
{
    private ValidationResult $validation;

    public function __construct(ValidationResult $validation, int $status, ?Throwable $previous = null)
    {
        if ($validation->isValid()) {
            throw new LogicException('Validation result must contain at least one violation');
        }
        parent::__construct(self::firstMessage($validation), $status, [], $previous);
        $this->validation = $validation;
    }

    public function validation(): ValidationResult
    {
        return $this->validation;
    }

    private static function firstMessage(ValidationResult $validation): string
    {
        // Never empty: the constructor throws on an empty result first
        return $validation->violations()[0]->fallback;
    }
}
