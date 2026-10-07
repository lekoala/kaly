<?php

declare(strict_types=1);

namespace Kaly\Util;

/**
 * Trivial `mixed` narrowing, permissive exactly where the name says so:
 * `*OrNull`/`*OrEmpty` return an explicit fallback, never a conversion.
 * It is the field-level companion of `Json::decodeMap()`/`decodeList()`:
 * they give the outer shape, this narrows each value on read.
 * No speculative primitives — a member is added only after the same pattern
 * repeats in the codebase. Anything carrying domain semantics (statuses,
 * domain dates, ids) stays in the domain, not here. Generic representation
 * parsing belongs in its dedicated utility.
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

    /**
     * Keep instances of a class or interface from a list, preserving order.
     * Non-list inputs return an empty list; matching subclasses are accepted.
     *
     * @template T of object
     * @param class-string<T> $type
     * @return list<T>
     */
    public static function instancesOf(mixed $value, string $type): array
    {
        $result = [];
        foreach (self::listOrEmpty($value) as $item) {
            if ($item instanceof $type) {
                $result[] = $item;
            }
        }
        return $result;
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
