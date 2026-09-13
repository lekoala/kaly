<?php

declare(strict_types=1);

namespace Kaly\Util;

use Psr\Http\Message\ResponseInterface;
use Stringable;
use Transliterator;

/**
 * Multibyte string helpers used by routing, logging and views.
 *
 * Reference implementations: nette/utils Strings, yiisoft/strings StringHelper,
 * mako-framework Str.
 *
 * @link https://github.com/nette/utils/blob/master/src/Utils/Strings.php
 * @link https://github.com/mako-framework/framework/blob/master/src/mako/utility/Str.php
 * @link https://github.com/yiisoft/strings/blob/2.x/src/StringHelper.php
 */
final class Str
{
    /**
     * Uppercase the first character, leave the rest untouched.
     */
    public static function ucFirst(?string $str, string $encoding = 'UTF-8'): string
    {
        $str ??= '';
        $firstChar = mb_substr($str, 0, 1, $encoding);
        $then = mb_substr($str, 1, null, $encoding);
        return mb_strtoupper($firstChar, $encoding) . $then;
    }

    /**
     * Uppercase the whole string (multibyte safe).
     */
    public static function upper(?string $str, string $encoding = 'UTF-8'): string
    {
        return mb_strtoupper($str ?? '', $encoding);
    }

    /**
     * Lowercase the whole string (multibyte safe).
     */
    public static function lower(?string $str, string $encoding = 'UTF-8'): string
    {
        return mb_strtolower($str ?? '', $encoding);
    }

    /**
     * Convert the first character of each word to uppercase.
     */
    public static function ucWords(?string $str, string $encoding = 'UTF-8'): string
    {
        return mb_convert_case((string) $str, MB_CASE_TITLE, $encoding);
    }

    /**
     * Build an URL-friendly slug, transliterating to ASCII when intl is available.
     *
     * Falls back to a best-effort ASCII slug when ext-intl is missing.
     *
     * See https://stackoverflow.com/questions/2955251/php-function-to-make-slug-url-string for prior art.
     */
    public static function slug(?string $str): string
    {
        if (!$str) {
            return '';
        }
        // Intl is only suggested: fall back to a best-effort ascii slug
        if (!class_exists(Transliterator::class)) {
            $fallback = preg_replace('/[^a-z0-9]+/i', '-', $str) ?? '';
            return trim(strtolower($fallback), '-');
        }
        $rules = <<<'RULES'
            :: Any-Latin;
            :: NFD;
            :: [:Nonspacing Mark:] Remove;
            :: NFC;
            :: [^-[:^Punctuation:]] Remove;
            :: Lower();
            [:^L:] { [-] > ;
            [-] } [:^L:] > ;
            [-[:Separator:]]+ > '-';
            RULES;
        return Transliterator::createFromRules($rules)?->transliterate($str) ?: '';
    }

    /**
     * Convert from any encoding to UTF-8, avoiding double encoding.
     *
     * Alternative to deprecated utf8_encode. See https://www.php.net/manual/en/function.utf8-encode.php.
     *
     * @param array<string>|string|null $fromEncoding Passed to mb_convert_encoding
     */
    public static function toUtf8(?string $str, string|array|null $fromEncoding = null): string
    {
        // Avoid double encoding
        if (self::isUtf8($str)) {
            return $str ?? '';
        }
        return self::convertEncoding($str, 'UTF-8', $fromEncoding);
    }

    /**
     * Convert from UTF-8 to the given encoding.
     *
     * Alternative to deprecated utf8_decode. See https://www.php.net/manual/en/function.utf8-decode.php.
     */
    public static function fromUtf8(?string $str, string $toEncoding): string
    {
        return self::convertEncoding($str, $toEncoding, 'UTF-8');
    }

    /**
     * Check whether a string is valid UTF-8.
     */
    public static function isUtf8(?string $str): bool
    {
        return !$str || preg_match('/^./su', $str);
    }

    /**
     * Convert string encoding, returning an empty string on failure.
     *
     * See https://stackoverflow.com/questions/8233517/what-is-the-difference-between-iconv-and-mb-convert-encoding-in-php.
     *
     * @param array<string>|string|null $from Passed to mb_convert_encoding
     */
    public static function convertEncoding(?string $str, ?string $to = null, string|array|null $from = null): string
    {
        $result = mb_convert_encoding($str ?? '', $to ?? 'UTF-8', $from);
        if ($result === false) {
            // Return non-fatal blank string on encoding errors from users
            return '';
        }
        return $result;
    }

    /**
     * Convert any value to a debug-friendly string.
     */
    public static function stringify(mixed $val): string
    {
        if (is_array($val)) {
            $val = json_encode($val, JSON_THROW_ON_ERROR);
        } elseif (is_object($val)) {
            if ($val instanceof ResponseInterface) {
                $val = 'Response: ' . Str::truncate((string) $val->getBody());
            } elseif ($val instanceof Stringable) {
                $val = (string) $val;
            } else {
                $val = $val::class;
            }
        } elseif (is_bool($val)) {
            $val = $val ? '(bool) true' : '(bool) false';
        } elseif (!is_string($val)) {
            $val = get_debug_type($val);
        }
        return $val;
    }

    /**
     * Truncate a string to the given length, stripping tags first.
     */
    public static function truncate(?string $str, int $chars = 120, string $append = '...'): string
    {
        if ($str === null) {
            return '';
        }
        $str = strip_tags($str);
        if (strlen($str) > $chars) {
            return substr($str, 0, $chars) . $append;
        }
        return $str;
    }

    /**
     * Transform a string to camel case.
     *
     * Preserves underscores, only dashes are treated as word separators so the
     * result stays a valid class or method name.
     */
    public static function camelize(string $str, bool $firstChar = true): string
    {
        if ($str === '') {
            return $str;
        }
        $str = str_replace('-', ' ', $str);
        $str = self::ucWords($str);
        $str = str_replace(' ', '', $str);
        if (!$firstChar) {
            $str[0] = mb_strtolower($str[0]);
        }
        return $str;
    }

    /**
     * Transform a camel case string to dash-separated lowercase.
     */
    public static function decamelize(string $str): string
    {
        if ($str === '') {
            return $str;
        }
        $str = preg_replace(['/([a-z\d])([A-Z])/', '/([^-_])([A-Z][a-z])/'], '$1-$2', $str);
        if (!$str) {
            return '';
        }
        return mb_strtolower($str);
    }
}
