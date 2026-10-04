<?php

declare(strict_types=1);

namespace Kaly\Util;

/**
 * The canonical encoding for transportable opaque tokens.
 *
 * Base64url is not more secure than standard Base64, it is simply neutral
 * in HTML, form-urlencoded bodies, headers and logs: no `+`, `/` or `=`
 * padding. Decoding is strict and canonical — malformed input returns
 * null, never throws, and non-canonical spellings of the same bytes are
 * rejected so one byte string owns exactly one spelling.
 */
final class Base64Url
{
    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function decode(string $value): ?string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/[^A-Za-z0-9_-]/', $value)) {
            return null;
        }

        $remainder = strlen($value) % 4;

        if ($remainder === 1) {
            return null;
        }

        $padded = strtr($value, '-_', '+/');

        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        if ($decoded === false) {
            return null;
        }

        return self::encode($decoded) === $value ? $decoded : null;
    }
}
