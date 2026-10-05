<?php

declare(strict_types=1);

namespace Kaly\Http\Input;

use Kaly\Validation\ValidationResult;

/**
 * The outcome of building an input, usable without throwing.
 *
 * Values hold the merged query and body before coercion so a form can
 * re-render exactly what was sent. The DTO only exists when every field could
 * be represented by its type; business rules run afterwards through
 * ValidatableInput. Mapping errors are a 400, refused values a 422, success
 * carries no status.
 */
final readonly class InputResult
{
    /**
     * @param array<string,mixed> $values
     */
    public function __construct(
        private array $values,
        private ?RequestInput $input,
        private ValidationResult $validation,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function values(): array
    {
        return $this->values;
    }

    public function input(): ?RequestInput
    {
        return $this->input;
    }

    public function validation(): ValidationResult
    {
        return $this->validation;
    }

    public function isValid(): bool
    {
        return $this->input !== null && $this->validation->isValid();
    }

    public function status(): ?int
    {
        if ($this->input === null) {
            return 400;
        }
        if (!$this->validation->isValid()) {
            return 422;
        }
        return null;
    }

    public function require(): RequestInput
    {
        if ($this->input === null) {
            throw new InputException($this->validation);
        }
        if (!$this->validation->isValid()) {
            throw new ValidationException($this->validation);
        }
        return $this->input;
    }
}
