<?php

declare(strict_types=1);

namespace Kaly\Util;

use Psr\Http\Message\ResponseInterface;
use Stringable;
use Transliterator;

/**
 * @link https://github.com/nette/utils/blob/master/src/Utils/Strings.php
 * @link https://github.com/mako-framework/framework/blob/master/src/mako/utility/Str.php
 * @link https://github.com/yiisoft/strings/blob/2.x/src/StringHelper.php
 */
final class Str
{
    public static function ucFirst(?string $str, ?string $encoding = "UTF-8"): string
    {
        $str ??= '';
        $firstChar = mb_substr($str, 0, 1, $encoding);
        $then = mb_substr($str, 1, null, $encoding);
        return mb_strtoupper($firstChar, $encoding) . $then;
    }

    public static function uppercaseFirst(?string $str, ?string $encoding = "UTF-8"): string
    {
        return self::ucFirst($str, $encoding);
    }

    public static function uc(?string $str, ?string $encoding = "UTF-8"): string
    {
        return mb_strtoupper($str ?? '', $encoding);
    }

    public static function uppercase(?string $str, ?string $encoding = "UTF-8"): string
    {
        return self::uc($str, $encoding);
    }

    public static function lcLast(?string $str, ?string $encoding = "UTF-8"): string
    {
        $str ??= '';
        $firstChar = mb_substr($str, 0, 1, $encoding);
        $then = mb_substr($str, 1, null, $encoding);
        return $firstChar . mb_strtolower($then);
    }

    public static function lowercaseLast(?string $str, ?string $encoding = "UTF-8"): string
    {
        return self::lcLast($str, $encoding);
    }

    public static function lc(?string $str, ?string $encoding = "UTF-8"): string
    {
        return mb_strtolower($str ?? '', $encoding);
    }

    public static function lowercase(?string $str, ?string $encoding = "UTF-8"): string
    {
        return self::lc($str, $encoding);
    }

    public static function firstChar(?string $str, ?string $encoding = "UTF-8"): string
    {
        return mb_substr($str ?? '', 0, 1, $encoding);
    }

    /**
     * Convert the first character of each word to uppercase and all the other characters to lowercase
     */
    public static function ucWords(?string $str, ?string $encoding = "UTF-8"): string
    {
        return mb_convert_case((string) $str, MB_CASE_TITLE, $encoding);
    }

    public static function uppercaseWords(?string $str, ?string $encoding = "UTF-8"): string
    {
        return self::ucWords($str, $encoding);
    }

    public static function startsWith(?string $haystack, string $needle): bool
    {
        $haystack ??= '';
        return str_starts_with($haystack, $needle);
    }

    public static function endsWith(?string $haystack, string $needle): bool
    {
        $haystack ??= '';
        return str_ends_with($haystack, $needle);
    }

    public static function contains(?string $haystack, string $needle): bool
    {
        $haystack ??= '';
        return str_contains($haystack, $needle);
    }

    public static function random(int $length = 13): string
    {
        $int = intval(ceil($length / 2));
        assert($int > 0);
        $bytes = random_bytes($int);
        return substr(bin2hex($bytes), 0, $length);
    }

    public static function endSlash(?string $str): string
    {
        $str ??= '';
        return rtrim($str, '/') . '/';
    }

    /**
     * @link https://stackoverflow.com/questions/2955251/php-function-to-make-slug-url-string
     * @link https://3v4l.org/FAJAW
     * @param string|null $str
     * @return string
     */
    public static function slug(?string $str): string
    {
        if (!$str) {
            return '';
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
     * @link https://stackoverflow.com/questions/1176904/how-to-remove-all-non-printable-characters-in-a-string
     * @param string $str
     * @return string
     */
    public static function removeInvisibleChars(?string $str): ?string
    {
        return preg_replace('/[^[:print:]]/', '', $str ?? '');
    }

    /**
     * @param string|null $str
     * @return string
     */
    public static function removeWhitespaces(?string $str): ?string
    {
        return preg_replace('/\s+/', '', $str ?? '');
    }

    /**
     * @param string|null $str
     * @return string
     */
    public static function trimSpaces(?string $str): string
    {
        // %C2%A0 => \xc2\xa0 (non breaking space utf8)
        return trim($str ?? '', "\t\n\r\0\x0B\xc2\xa0");
    }

    /**
     * Alternative to deprecated utf8_encode
     * @link https://www.php.net/manual/en/function.utf8-encode.php
     * @param string|null $str
     * @param string|array<string>|null $fromEncoding
     * @return string
     */
    public static function toUtf8(?string $str, string|array|null $fromEncoding = null): string
    {
        // Avoid double encoding
        if (self::isUtf8($str)) {
            return $str ?? '';
        }
        return self::convertEncoding($str, "UTF-8", $fromEncoding);
    }

    /**
     * Alternative to deprecated utf8_decode
     * @link https://www.php.net/manual/en/function.utf8-decode.php
     * @param string|null $str
     * @param string $toEncoding
     * @return string
     */
    public static function fromUtf8(?string $str, string $toEncoding): string
    {
        return self::convertEncoding($str, $toEncoding, "UTF-8");
    }

    public static function isUtf8(?string $str): bool
    {
        return !$str || preg_match('/^./su', $str);
    }

    /**
     * @link https://stackoverflow.com/questions/8233517/what-is-the-difference-between-iconv-and-mb-convert-encoding-in-php
     * @param string|null $str
     * @param string|null $to
     * @param string|array<string>|null|null $from
     * @return string
     */
    public static function convertEncoding(?string $str, ?string $to = null, string|array|null $from = null): string
    {
        $result = mb_convert_encoding($str ?? '', $to ?? "UTF-8", $from);
        if ($result === false) {
            return ''; // return non-fatal blank string on encoding errors from users
        }
        return $result;
    }

    public static function stringify(mixed $val): string
    {
        if (is_array($val)) {
            $val = json_encode($val, JSON_THROW_ON_ERROR);
        } elseif (is_object($val)) {
            if ($val instanceof ResponseInterface) {
                $val = "Response: " . Str::truncate((string)$val->getBody());
            } elseif ($val instanceof Stringable) {
                $val = (string)$val;
            } else {
                $val = $val::class;
            }
        } elseif (is_bool($val)) {
            $val = $val ? "(bool) true" : "(bool) false";
        } elseif (!is_string($val)) {
            $val = get_debug_type($val);
        }
        return $val;
    }

    public static function truncate(?string $str, int $chars = 120, string $append = "..."): string
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
     * Transform a string to camel case
     * Preserves _, it only replaces - because it could be a valid class or method names
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
     * Does the opposite of camelize
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
        $str = mb_strtolower($str);
        return $str;
    }
}
