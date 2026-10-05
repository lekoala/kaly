<?php

declare(strict_types=1);

namespace Kaly\Validation;

/**
 * A failure that carries its structured validation outcome.
 *
 * The exception handler renders the result as a list of errors; the localized
 * handler translates each violation first without losing the structure.
 */
interface HasValidationResult
{
    public function validation(): ValidationResult;
}
