<?php

// This file is always included by the autoloader

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
function array_merge_distinct(array &$arr1, array &$arr2, bool $deep = true): array
{
    foreach ($arr2 as $k => $v) {
        // regular array values are appended
        if (is_int($k)) {
            $arr1[] = $v;
            continue;
        }
        // associative arrays work by keys
        if (isset($arr1[$k]) && is_array($arr1[$k])) {
            if ($deep) {
                $arr1[$k] = array_merge_distinct($arr1[$k], $v, $deep);
            } else {
                $arr1[$k] = array_merge($arr1[$k], $v);
            }
        } else {
            $arr1[$k] = $v;
        }
    }
    return $arr1;
}

/**
 * Transform a string to camel case
 * Preserves _, it only replaces -
 */
function camelize(string $str, bool $firstChar = true): string
{
    if ($str === '') {
        return $str;
    }
    $str = str_replace('-', ' ', $str);
    $str = mb_convert_case($str, MB_CASE_TITLE, "UTF-8");
    $str = str_replace(' ', '', $str);
    if (!$firstChar) {
        $str[0] = mb_strtolower($str[0]);
    }
    return $str;
}

function decamelize(string $str): string
{
    if ($str === '') {
        return $str;
    }
    $str = (string)preg_replace(['/([a-z\d])([A-Z])/', '/([^-])([A-Z][a-z])/'], '$1-$2', $str);
    return mb_strtolower($str);
}

function esc(string $content): string
{
    return htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true);
}
