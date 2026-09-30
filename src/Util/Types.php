<?php

declare(strict_types=1);

namespace Kaly\Util;

/**
 * Trivial `mixed` narrowing, permissive exactly where the name says so:
 * `*OrNull`/`*OrEmpty` return an explicit fallback, never a conversion.
 * No speculative primitives — a member is added only after the same pattern
 * repeats in the codebase. Anything carrying domain semantics (statuses,
 * dates, ids) stays in the domain, not here.
 */
final class Types
{
    public static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    public static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    public static function boolOrNull(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /** @return list<mixed> */
    public static function listOrEmpty(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /** @return array<string, mixed> */
    public static function mapOrEmpty(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                return [];
            }
        }

        /** @var array<string, mixed> $value verified above */
        return $value;
    }
}
