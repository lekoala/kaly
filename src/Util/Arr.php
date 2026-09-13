<?php

declare(strict_types=1);

namespace Kaly\Util;

use Stringable;

/**
 * Array helpers used by the framework and its consumers.
 *
 * Reference implementations: nette/utils Arrays, yiisoft/arrays ArrayHelper.
 *
 * @link https://github.com/nette/utils/blob/master/src/Utils/Arrays.php
 * @link https://github.com/yiisoft/arrays/blob/master/src/ArrayHelper.php
 */
final class Arr
{
    /**
     * Merge two arrays, overwriting string keys instead of casting them to arrays.
     *
     * Unlike array_merge_recursive, scalar values are overwritten. Integer keys
     * are appended. Arguments are passed by reference for performance.
     *
     * @param array<mixed> $arr1 Base array, modified in place and returned
     * @param array<mixed> $arr2 Values to merge into $arr1
     * @param bool $deep Merge nested arrays recursively
     * @return array<mixed> The merged array
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
     * Return keys where values differ between two arrays.
     *
     * @param array<mixed> $old Previous values
     * @param array<mixed> $new New values
     * @return array<mixed> Map of key to [old, new] pairs
     */
    public static function compare(array $old, array $new): array
    {
        $arr = [];
        foreach ($new as $k => $v) {
            $ov = $old[$k] ?? null;
            if ($ov !== $v) {
                $arr[$k] = [$ov, $v];
            }
        }
        return $arr;
    }

    /**
     * Map values, preserving keys.
     *
     * @param callable $fn Receives the value, returns the new value
     * @param array<mixed> $arr Input array
     * @return array<mixed>
     */
    public static function map(callable $fn, array $arr): array
    {
        return array_map($fn, $arr);
    }

    /**
     * Map with access to both key and value.
     *
     * Unlike array_map, the callback receives ($key, $value).
     *
     * Example: Arr::mapAssoc(fn($key, $value) => [$key => $value], $items)
     *
     * @param callable $callback Receives ($key, $value)
     * @param array<mixed> $array Input array
     * @return array<mixed>
     */
    public static function mapAssoc(callable $callback, array $array): array
    {
        return array_map(static fn($key) => $callback($key, $array[$key]), array_keys($array));
    }

    /**
     * Convert all values to string or nested arrays of strings.
     *
     * @param array<mixed,mixed> $arr Input array
     * @return array<array<string>|string>
     */
    public static function stringValues(array $arr): array
    {
        //@phpstan-ignore-next-line
        return self::map(static function ($v) {
            if (is_array($v)) {
                return self::stringValues($v);
            }
            if ($v instanceof Stringable) {
                return (string) $v;
            }
            return Str::stringify($v);
        }, $arr);
    }
}
