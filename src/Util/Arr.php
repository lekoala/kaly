<?php

declare(strict_types=1);

namespace Kaly\Util;

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
     * are appended.
     *
     * @param array<array-key,mixed> $arr1 Base array
     * @param array<array-key,mixed> $arr2 Values to merge into $arr1
     * @param bool $deep Merge nested arrays recursively
     * @return array<array-key,mixed> The merged array. A deep merge of nested
     *         arrays widens the values, so callers that need a narrower shape
     *         narrow the result themselves.
     */
    public static function mergeDistinct(array $arr1, array $arr2, bool $deep = true): array
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
     * Map with access to both key and value, preserving the keys.
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
        $result = [];
        foreach ($array as $key => $value) {
            $result[$key] = $callback($key, $value);
        }
        return $result;
    }
}
