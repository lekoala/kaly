<?php

declare(strict_types=1);

namespace Kaly\Http;

/**
 * An input that carries its own business rules.
 *
 * Mapping answers "can this data be represented by this type?". Validation
 * answers "is this typed value acceptable?" and belongs to the input itself,
 * either in its constructor or here when the rules need the whole object.
 *
 * It runs once the input has been fully built by the mapper.
 */
interface ValidatableInput extends RequestInput
{
    /**
     * @throws ValidationException When the input is well typed but refused
     */
    public function validate(): void;
}
