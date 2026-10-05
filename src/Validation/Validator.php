<?php

declare(strict_types=1);

namespace Kaly\Validation;

use InvalidArgumentException;

/**
 * Collects violations for already typed values.
 *
 * The PHP type stays the schema: the validator only refuses values the type
 * alone accepts. Every rule ignores null because nullability belongs to the
 * type itself. Misconfigured rules are programming errors and throw instead of
 * producing user-facing violations.
 */
final class Validator
{
    /**
     * @var list<Violation>
     */
    private array $violations = [];

    public function add(Violation $violation): self
    {
        $this->violations[] = $violation;
        return $this;
    }

    public function result(): ValidationResult
    {
        return new ValidationResult($this->violations);
    }

    public function notBlank(string $field, ?string $value): self
    {
        if ($value === null) {
            return $this;
        }
        if (preg_match('/^\s*$/u', $value) === 1) {
            $this->add(new Violation($field, 'not_blank', 'not_blank', 'This value must not be blank'));
        }
        return $this;
    }

    public function email(string $field, ?string $value): self
    {
        if ($value === null) {
            return $this;
        }
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            $this->add(new Violation($field, 'email', 'email', 'This value is not a valid email address'));
        }
        return $this;
    }

    public function length(string $field, ?string $value, ?int $min = null, ?int $max = null): self
    {
        // A misconfigured rule throws even when there is nothing to check
        $this->guardBounds($min, $max);
        if ($value === null) {
            return $this;
        }
        $length = mb_strlen($value, 'UTF-8');
        if ($min !== null && $max !== null) {
            if ($length < $min || $length > $max) {
                $this->add(new Violation(
                    $field,
                    'length_between',
                    'length_between',
                    "This value must be between {$min} and {$max} characters long",
                    ['%min%' => $min, '%max%' => $max],
                ));
            }
            return $this;
        }
        if ($min !== null && $length < $min) {
            $this->add(new Violation($field, 'length_min', 'length_min', "This value must be at least {$min} characters long", [
                '%min%' => $min,
            ]));
        }
        if ($max !== null && $length > $max) {
            $this->add(new Violation($field, 'length_max', 'length_max', "This value must be at most {$max} characters long", [
                '%max%' => $max,
            ]));
        }
        return $this;
    }

    public function minLength(string $field, ?string $value, int $min): self
    {
        return $this->length($field, $value, min: $min);
    }

    public function maxLength(string $field, ?string $value, int $max): self
    {
        return $this->length($field, $value, max: $max);
    }

    public function between(string $field, int|float|null $value, int|float|null $min = null, int|float|null $max = null): self
    {
        // A misconfigured rule throws even when there is nothing to check
        $this->guardBounds($min, $max);
        if ($value === null) {
            return $this;
        }
        if ($min !== null && $max !== null) {
            if ($value < $min || $value > $max) {
                $this->add(new Violation($field, 'between', 'between', "This value must be between {$min} and {$max}", [
                    '%min%' => $min,
                    '%max%' => $max,
                ]));
            }
            return $this;
        }
        if ($min !== null && $value < $min) {
            $this->add(new Violation($field, 'between_min', 'between_min', "This value must be at least {$min}", ['%min%' => $min]));
        }
        if ($max !== null && $value > $max) {
            $this->add(new Violation($field, 'between_max', 'between_max', "This value must be at most {$max}", ['%max%' => $max]));
        }
        return $this;
    }

    /**
     * @param array<mixed> $values
     */
    public function oneOf(string $field, string|int|float|bool|null $value, array $values): self
    {
        if ($value === null) {
            return $this;
        }
        if (!in_array($value, $values, true)) {
            $this->add(new Violation($field, 'one_of', 'one_of', 'This value is not one of the allowed values'));
        }
        return $this;
    }

    public function matches(string $field, ?string $value, string $pattern): self
    {
        // A misconfigured rule throws even when there is nothing to check
        if (@preg_match($pattern, '') === false) {
            throw new InvalidArgumentException('Invalid regular expression');
        }
        if ($value === null) {
            return $this;
        }
        if (preg_match($pattern, $value) !== 1) {
            $this->add(new Violation($field, 'pattern', 'pattern', 'This value has an invalid format'));
        }
        return $this;
    }

    /**
     * @param array<mixed>|null $value
     */
    public function count(string $field, ?array $value, ?int $min = null, ?int $max = null): self
    {
        // A misconfigured rule throws even when there is nothing to check
        $this->guardBounds($min, $max);
        if ($value === null) {
            return $this;
        }
        $size = count($value);
        if ($min !== null && $max !== null) {
            if ($size < $min || $size > $max) {
                $this->add(new Violation(
                    $field,
                    'count_between',
                    'count_between',
                    "This collection must contain between {$min} and {$max} items",
                    ['%min%' => $min, '%max%' => $max],
                ));
            }
            return $this;
        }
        if ($min !== null && $size < $min) {
            $this->add(new Violation($field, 'count_min', 'count_min', "This collection must contain at least {$min} items", [
                '%min%' => $min,
            ]));
        }
        if ($max !== null && $size > $max) {
            $this->add(new Violation($field, 'count_max', 'count_max', "This collection must contain at most {$max} items", [
                '%max%' => $max,
            ]));
        }
        return $this;
    }

    private function guardBounds(int|float|null $min, int|float|null $max): void
    {
        if ($min === null && $max === null) {
            throw new InvalidArgumentException('At least one bound must be set');
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new InvalidArgumentException('The minimum must not be greater than the maximum');
        }
    }
}
