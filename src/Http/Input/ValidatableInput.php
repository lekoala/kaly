<?php

declare(strict_types=1);

namespace Kaly\Http\Input;

use Kaly\Validation\Validator;

/**
 * An input that carries its own business rules.
 *
 * Mapping answers "can this data be represented by this type?". Validation
 * answers "is this typed value acceptable?" and belongs here: the constructor
 * of a request input never validates, so a form can always be rebuilt from
 * the submitted values.
 *
 * It runs once the input has been fully built by the mapper.
 */
interface ValidatableInput extends RequestInput
{
    public function validate(Validator $validator): void;
}
