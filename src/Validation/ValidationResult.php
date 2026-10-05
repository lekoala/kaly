<?php

declare(strict_types=1);

namespace Kaly\Validation;

/**
 * The collected outcome of validation, usable without HTTP.
 *
 * A form keeps the result to render its fields; only the HTTP layer turns a
 * failing result into an exception.
 */
final readonly class ValidationResult
{
    /**
     * @param list<Violation> $violations
     */
    public function __construct(
        private array $violations = [],
    ) {}

    public function isValid(): bool
    {
        return $this->violations === [];
    }

    /**
     * @return list<Violation>
     */
    public function violations(): array
    {
        return $this->violations;
    }

    /**
     * @return list<Violation>
     */
    public function for(?string $field): array
    {
        return array_values(array_filter($this->violations, static fn(Violation $violation): bool => $violation->field === $field));
    }

    public function with(Violation ...$violations): self
    {
        return new self(array_values([...$this->violations, ...$violations]));
    }
}
