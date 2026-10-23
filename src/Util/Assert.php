<?php

declare(strict_types=1);

namespace Kaly\Util;

use Kaly\Ex;

/**
 * Guarantees an expected invariant, throwing on failure.
 *
 * It does not sanitize, coerce, filter, or validate user input: coercion is
 * `Cast`, silent fallback is `Types`, and user-facing violations are
 * `Validation\Validator`. Every method returns the narrowed value so callers
 * work with guaranteed types, and static analysis narrows them too.
 */
final class Assert
{
    /**
     * @template T
     * @param T $value
     * @phpstan-assert !null $value
     * @psalm-assert !null $value
     * @phpstan-return ($value is null ? never : T)
     * @psalm-return ($value is null ? never : T)
     * @return T
     */
    public static function notNull(mixed $value, ?string $message = null): mixed
    {
        if ($value === null) {
            throw new Ex($message ?? 'Expected non-null value, got null');
        }
        return $value;
    }

    /**
     * @phpstan-assert string $value
     * @psalm-assert string $value
     */
    public static function string(mixed $value, ?string $message = null): string
    {
        if (!is_string($value)) {
            self::fail('string', $value, $message);
        }
        return $value;
    }

    /**
     * @phpstan-assert non-empty-string $value
     * @psalm-assert non-empty-string $value
     * @return non-empty-string
     */
    public static function stringNotEmpty(mixed $value, ?string $message = null): string
    {
        if (!is_string($value) || $value === '') {
            self::fail('non-empty string', $value, $message);
        }
        return $value;
    }

    /**
     * @phpstan-assert int $value
     * @psalm-assert int $value
     */
    public static function integer(mixed $value, ?string $message = null): int
    {
        if (!is_int($value)) {
            self::fail('integer', $value, $message);
        }
        return $value;
    }

    /**
     * @phpstan-assert positive-int $value
     * @psalm-assert positive-int $value
     * @return positive-int
     */
    public static function positiveInteger(mixed $value, ?string $message = null): int
    {
        if (!is_int($value) || $value < 1) {
            self::fail('positive integer', $value, $message);
        }
        return $value;
    }

    /**
     * @phpstan-assert bool $value
     * @psalm-assert bool $value
     */
    public static function boolean(mixed $value, ?string $message = null): bool
    {
        if (!is_bool($value)) {
            self::fail('boolean', $value, $message);
        }
        return $value;
    }

    /**
     * @phpstan-assert array<array-key, mixed> $value
     * @psalm-assert array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    public static function array(mixed $value, ?string $message = null): array
    {
        if (!is_array($value)) {
            self::fail('array', $value, $message);
        }
        return $value;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @phpstan-assert T $value
     * @psalm-assert T $value
     * @return T
     */
    public static function instanceOf(mixed $value, string $class, ?string $message = null): object
    {
        if (!$value instanceof $class) {
            throw new Ex($message ?? "Expected instance of {$class}, got " . get_debug_type($value));
        }
        /** @var T $value verified above */
        return $value;
    }

    /**
     * An empty array is a valid list.
     *
     * @phpstan-assert list<mixed> $value
     * @psalm-assert list<mixed> $value
     * @return list<mixed>
     */
    public static function isList(mixed $value, ?string $message = null): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            self::fail('list', $value, $message);
        }
        return $value;
    }

    /**
     * An empty array is a valid map. A non-empty list is not a map, and a
     * mixed array of integer and string keys is neither.
     *
     * @phpstan-assert array<string, mixed> $value
     * @psalm-assert array<string, mixed> $value
     * @return array<string, mixed>
     */
    public static function isMap(mixed $value, ?string $message = null): array
    {
        if (!is_array($value)) {
            self::fail('map', $value, $message);
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                self::fail('map', $value, $message);
            }
        }
        /** @var array<string, mixed> $value verified above */
        return $value;
    }

    /**
     * Every element must be an instance: nothing is filtered out. An empty
     * list is valid, a non-list input fails. The class-string is assumed
     * valid, so no existence check or autoload is triggered.
     *
     * @template T of object
     * @param class-string<T> $class
     * @phpstan-assert list<T> $value
     * @psalm-assert list<T> $value
     * @return list<T>
     */
    public static function allInstanceOf(mixed $value, string $class, ?string $message = null): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new Ex($message ?? "Expected list of {$class}, got " . get_debug_type($value));
        }
        foreach ($value as $item) {
            if (!$item instanceof $class) {
                throw new Ex($message ?? "Expected list of {$class}, got element of type " . get_debug_type($item));
            }
        }
        /** @var list<T> $value verified above */
        return $value;
    }

    /**
     * The key must exist; a null value is fine.
     *
     * @phpstan-assert array<array-key, mixed> $value
     * @psalm-assert array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    public static function keyExists(mixed $value, string|int $key, ?string $message = null): array
    {
        if (!is_array($value)) {
            self::fail('array', $value, $message);
        }
        if (!array_key_exists($key, $value)) {
            throw new Ex($message ?? "Expected array with key '{$key}'");
        }
        return $value;
    }

    /**
     * Strict identity: after success the value is the expected one.
     *
     * @template T
     * @param T $expected
     * @phpstan-assert T $value
     * @psalm-assert T $value
     * @return T
     */
    public static function same(mixed $value, mixed $expected, ?string $message = null): mixed
    {
        if ($value !== $expected) {
            throw new Ex($message ?? 'Expected identical ' . get_debug_type($expected) . ', got ' . get_debug_type($value));
        }
        return $value;
    }

    /**
     * Strict membership: after success the value is one of the allowed ones.
     *
     * @template T
     * @param array<array-key, T> $values
     * @phpstan-assert T $value
     * @psalm-assert T $value
     * @return T
     */
    public static function oneOf(mixed $value, array $values, ?string $message = null): mixed
    {
        if (!in_array($value, $values, true)) {
            throw new Ex($message ?? 'Expected one of the allowed values, got ' . get_debug_type($value));
        }
        return $value;
    }

    /**
     * Strictly numeric comparison: numeric strings never count.
     *
     * @phpstan-assert int|float $value
     * @psalm-assert int|float $value
     */
    public static function greaterThan(mixed $value, int|float $bound, ?string $message = null): int|float
    {
        if (!is_int($value) && !is_float($value)) {
            self::fail('int|float', $value, $message);
        }
        if (is_float($value) && is_nan($value) || is_float($bound) && is_nan($bound)) {
            throw new Ex($message ?? 'Expected number greater than a valid bound, got NAN');
        }
        if ($value <= $bound) {
            throw new Ex($message ?? "Expected number greater than {$bound}");
        }
        return $value;
    }

    /**
     * Inclusive bounds, strictly numeric: numeric strings never count. NAN
     * is refused for the value and the bounds, as plain comparisons would
     * otherwise let it through.
     *
     * @phpstan-assert int|float $value
     * @psalm-assert int|float $value
     */
    public static function range(mixed $value, int|float $min, int|float $max, ?string $message = null): int|float
    {
        if (!is_int($value) && !is_float($value)) {
            self::fail('int|float', $value, $message);
        }
        if ($min > $max) {
            throw new Ex($message ?? 'The minimum must not be greater than the maximum');
        }
        if (is_float($value) && is_nan($value) || is_float($min) && is_nan($min) || is_float($max) && is_nan($max)) {
            throw new Ex($message ?? 'Expected number within valid bounds, got NAN');
        }
        if ($value < $min || $value > $max) {
            throw new Ex($message ?? "Expected number between {$min} and {$max}");
        }
        return $value;
    }

    /**
     * @phpstan-assert string $value
     * @psalm-assert string $value
     */
    public static function startsWith(mixed $value, string $needle, ?string $message = null): string
    {
        if (!is_string($value)) {
            self::fail('string', $value, $message);
        }
        if (!str_starts_with($value, $needle)) {
            throw new Ex($message ?? "Expected string starting with '{$needle}'");
        }
        return $value;
    }

    /**
     * @phpstan-assert string $value
     * @psalm-assert string $value
     */
    public static function contains(mixed $value, string $needle, ?string $message = null): string
    {
        if (!is_string($value)) {
            self::fail('string', $value, $message);
        }
        if (!str_contains($value, $needle)) {
            throw new Ex($message ?? "Expected string containing '{$needle}'");
        }
        return $value;
    }

    /**
     * An invalid pattern is a programming error and throws even when the
     * value itself would fail.
     *
     * @phpstan-assert string $value
     * @psalm-assert string $value
     */
    public static function matches(mixed $value, string $pattern, ?string $message = null): string
    {
        if (@preg_match($pattern, '') === false) {
            throw new Ex($message ?? 'Invalid regular expression');
        }
        if (!is_string($value)) {
            self::fail('string', $value, $message);
        }
        if (preg_match($pattern, $value) !== 1) {
            throw new Ex($message ?? 'Expected string matching the pattern');
        }
        return $value;
    }

    /**
     * Inclusive bounds in characters (multibyte safe). Negative bounds are
     * programming errors, like an inverted range.
     *
     * @phpstan-assert string $value
     * @psalm-assert string $value
     */
    public static function lengthBetween(mixed $value, int $min, int $max, ?string $message = null): string
    {
        if ($min < 0 || $max < 0) {
            throw new Ex($message ?? 'Bounds must not be negative');
        }
        if ($min > $max) {
            throw new Ex($message ?? 'The minimum must not be greater than the maximum');
        }
        if (!is_string($value)) {
            self::fail('string', $value, $message);
        }
        $length = mb_strlen($value, 'UTF-8');
        if ($length < $min || $length > $max) {
            throw new Ex($message ?? "Expected string length between {$min} and {$max}");
        }
        return $value;
    }

    private static function fail(string $expected, mixed $actual, ?string $message): never
    {
        throw new Ex($message ?? "Expected {$expected}, got " . get_debug_type($actual));
    }
}
