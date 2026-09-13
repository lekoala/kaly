<?php

declare(strict_types=1);

namespace Kaly\Router;

/**
 * Coerces url segments to the scalar types of an action signature.
 *
 * Coercion is strict: an invalid value does not match the route and raises a
 * RouteNotFoundException (404) instead of flowing into the action.
 */
final class RouteParamCoercer
{
    /**
     * Coerce a segment to a builtin type name.
     *
     * @return int|float|bool|array<int,string>|string
     */
    public static function coerce(string $type, string $value): int|float|bool|array|string
    {
        return match ($type) {
            'bool' => self::bool($value),
            'array' => explode(',', $value),
            'int' => self::int($value),
            'float' => self::float($value),
            default => $value,
        };
    }

    public static function int(string $value): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        if ($int === false) {
            throw new RouteNotFoundException("Invalid integer value '{$value}'");
        }
        return $int;
    }

    public static function float(string $value): float
    {
        $float = filter_var($value, FILTER_VALIDATE_FLOAT);
        if ($float === false) {
            throw new RouteNotFoundException("Invalid float value '{$value}'");
        }
        return $float;
    }

    public static function bool(string $value): bool
    {
        $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($bool === null) {
            throw new RouteNotFoundException("Invalid boolean value '{$value}'");
        }
        return $bool;
    }
}
