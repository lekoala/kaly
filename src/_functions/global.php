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
 * Convert the first character of each word to uppercase
 * and all the other characters to lowercase
 */
function mb_strtotitle(string $str): string
{
    return mb_convert_case($str, MB_CASE_TITLE, "UTF-8");
}

/**
 * Transform a string to camel case
 * Preserves _, it only replaces - because the could be
 * valid class or method names
 */
function camelize(string $str, bool $firstChar = true): string
{
    if ($str === '') {
        return $str;
    }
    $str = str_replace('-', ' ', $str);
    $str = mb_strtotitle($str);
    $str = str_replace(' ', '', $str);
    if (!$firstChar) {
        $str[0] = mb_strtolower($str[0]);
    }
    return $str;
}

/**
 * Does the opposite of camelize
 */
function decamelize(string $str): string
{
    if ($str === '') {
        return $str;
    }
    $str = preg_replace(['/([a-z\d])([A-Z])/', '/([^-_])([A-Z][a-z])/'], '$1-$2', $str);
    if (!$str) {
        return '';
    }
    $str = mb_strtolower($str);
    return $str;
}

function esc(string $content): string
{
    return htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true);
}

function get_class_name(string $class): string
{
    $parts = explode('\\', $class);
    return end($parts);
}

/**
 * @param mixed $val
 */
function stringify($val): string
{
    if (is_array($val)) {
        $val = json_encode($val, JSON_THROW_ON_ERROR);
    } elseif (is_object($val)) {
        if ($val instanceof Stringable) {
            $val = (string)$val;
        } else {
            $val = get_class($val);
        }
    } elseif (is_bool($val)) {
        $val = $val ? "(bool) true" : "(bool) false";
    } elseif (!is_string($val)) {
        $val = get_debug_type($val);
    }
    return $val;
}

/**
 * @return array<string>
 */
function glob_recursive(string $pattern, int $flags = 0): array
{
    $files = glob($pattern, $flags);
    if (!$files) {
        $files = [];
    }
    $dirs = glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT);
    if (!$dirs) {
        $dirs = [];
    }
    foreach ($dirs as $dir) {
        $files = array_merge($files, glob_recursive($dir . '/' . basename($pattern), $flags));
    }
    return $files;
}

if (!function_exists('t')) {
    /**
     * @param array<string, mixed> $parameters
     */
    function t(string $message, array $parameters = [], string $domain = null, string $locale = null): string
    {
        static $translator = null;
        if ($translator === null) {
            /** @var \Kaly\Translator $translator  */
            $translator = \Kaly\App::inst()->getDi()->get(\Kaly\Translator::class);
        }
        return $translator->translate($message, $parameters, $domain, $locale);
    }
}
