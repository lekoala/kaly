<?php

declare(strict_types=1);

namespace Kaly\Util;

use Stringable;

/**
 * @link https://github.com/nette/utils/blob/master/src/Utils/Arrays.php
 * @link https://github.com/yiisoft/arrays/blob/master/src/ArrayHelper.php
 */
final class Arr
{
    /**
     * @link https://stackoverflow.com/questions/2699086/how-to-sort-a-multi-dimensional-array-by-value
     * @param array<mixed> $arr An array of objects
     * @param string $col
     * @param string $subcol
     * @param boolean $desc sort in desc order
     * @param boolean $subdesc sort in desc for subcol
     * @return void
     */
    public static function sortField(
        array &$arr,
        string $col,
        ?string $subcol = null,
        bool $desc = false,
        ?bool $subdesc = null
    ): void {
        $subdesc ??= $desc;
        usort($arr, function ($a, $b) use ($col, $subcol, $desc, $subdesc): int {
            $retval = $desc ? $b->$col <=> $a->$col : $a->$col <=> $b->$col;
            if ($retval == 0) {
                $retval = $subdesc ? $b->$subcol <=> $a->$subcol : $a->$subcol <=> $b->$subcol;
            }
            return $retval;
        });
    }

    /**
     * @param array<string> $array
     * @param string $column
     * @param string $key
     * @return int|string|false
     */
    public static function searchMulti(array $array, string $column, string $key): int|string|false
    {
        return (self::find($key, array_column($array, $column)));
    }

    /**
     * @param string $needle
     * @param array<string> $haystack
     * @param string|null $column
     * @return int|string|false
     */
    public static function find(string $needle, array $haystack, ?string $column = null): int|string|false
    {
        if (isset($haystack[0]) && is_array($haystack[0]) === true) { // check for multidimentional array
            foreach (array_column($haystack, $column) as $key => $value) {
                if (str_contains(strtolower((string) $value), strtolower((string) $needle))) {
                    return $key;
                }
            }
        } else {
            foreach ($haystack as $key => $value) { // for normal array
                if (str_contains(strtolower((string) $value), strtolower((string) $needle))) {
                    return $key;
                }
            }
        }
        return false;
    }

    /**
     * This function replaces array_merge_recursive which doesn't preserve datatypes
     * (two strings will be merged into one array, instead of overwriting the value).
     *
     * Arguments are passed as reference for performance reason
     *
     * @param array<mixed> $arr1
     * @param array<mixed> $arr2
     * @param bool $deep
     * @return array<mixed>
     */
    public static function mergeDistinct(array &$arr1, array &$arr2, bool $deep = true): array
    {
        foreach ($arr2 as $k => $v) {
            // regular array values are appended
            if (is_int($k)) {
                $arr1[] = $v;
                continue;
            }
            // merge arrays together if possible
            if (isset($arr1[$k]) && is_array($arr1[$k]) && is_array($v)) {
                if ($deep) {
                    $arr1[$k] = self::mergeDistinct($arr1[$k], $v, $deep);
                } else {
                    $arr1[$k] = array_merge($arr1[$k], $v);
                }
            } else {
                // simply overwrite value
                $arr1[$k] = $v;
            }
        }
        return $arr1;
    }

    /**
     * @param array<mixed> $old
     * @param array<mixed> $new
     * @return array<mixed>
     */
    public static function compare(array $old, array $new): array
    {
        $arr = [];
        foreach ($new as $k => $v) {
            $ov = $old[$k] ?? null;
            if ($ov != $v) {
                $arr[$k] = [$ov, $v];
            }
        }
        return $arr;
    }

    /**
     * @param callable $fn
     * @param array<mixed> $arr
     * @return array<mixed>
     */
    public static function map(callable $fn, array $arr): array
    {
        return array_map($fn, $arr);
    }

    /**
     * @param callable $fn
     * @param array<mixed> $arr
     * @return array<mixed>
     */
    public static function mapKeys(callable $fn, array $arr): array
    {
        return array_combine(
            //@phpstan-ignore-next-line
            array_map($fn, array_keys($arr)),
            $arr
        );
    }

    /**
     * Apply a mapping callback receiving key and value as arguments.
     * The standard array_map doesn't pass the key to the callback. But in the case of associative arrays,
     * it could be really helpful.
     *
     * array_map_assoc(function ($key, $value) {
     *  ...
     * }, $items)
     *
     * @param callable $callback
     * @param array<mixed> $array
     * @return array<mixed>
     */
    public static function mapAssoc(callable $callback, array $array): array
    {
        return array_map(fn($key) => $callback($key, $array[$key]), array_keys($array));
    }

    /**
     * @param callable $n
     * @param array<mixed> $arr
     * @return array<mixed>
     */
    public static function mapRecursive(callable $n, array $arr): array
    {
        array_walk_recursive($arr, function (&$v) use ($n): void {
            $v = $n($v);
        });
        return $arr;
    }

    /**
     * Convert values in an array into string or array of strings
     * @param array<array<Stringable>|Stringable> $arr
     * @return array<array<string>|string>
     */
    public static function stringValues(array $arr): array
    {
        return self::map(function ($v) {
            if (is_array($v)) {
                return self::stringValues($v);
            }
            if ($v instanceof Stringable) {
                return (string)$v;
            }
            return Str::stringify($v);
        }, $arr);
    }
}
