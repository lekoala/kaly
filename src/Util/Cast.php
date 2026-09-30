<?php

declare(strict_types=1);

namespace Kaly\Util;

/**
 * Scalar coercion of loose input — form fields, env vars, url segments.
 *
 * Returns null on failure, never throws: the caller owns what a failure
 * means (a 404 route mismatch, a 400 input error, a default value). The
 * companion of Types, which narrows `mixed` without ever converting.
 */
final class Cast
{
    public static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $int = filter_var($value, FILTER_VALIDATE_INT);
        return $int === false ? null : $int;
    }

    public static function floatOrNull(mixed $value): ?float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $float = filter_var($value, FILTER_VALIDATE_FLOAT);
        return $float === false ? null : $float;
    }

    public static function boolOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!is_scalar($value)) {
            return null;
        }
        return filter_var((string) $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
