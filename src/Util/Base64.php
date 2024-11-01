<?php

declare(strict_types=1);

namespace Kaly\Util;

use RuntimeException;

/**
 * Typesafe Base64 utilities
 *
 * @link https://base64.guru/developers/php/examples/base64url
 * @link https://www.php.net/manual/en/function.base64-encode.php
 */
final class Base64
{
    public static function encode(?string $string): string
    {
        return base64_encode($string ?? '');
    }

    public static function decode(?string $string): string
    {
        if (!$string) {
            return '';
        }
        $result = base64_decode($string);
        if ($result === false) {
            throw new RuntimeException("Could not decode value");
        }
        return $result;
    }

    public static function encodeAttr(?string $string): string
    {
        $url = strtr(self::encode($string), '\\', '_');
        // Remove padding character from the end of line
        return rtrim($url, '=');
    }

    public static function decodeAttr(?string $string): string
    {
        $string = strtr($string ?? '', '_', '\\');
        return self::decode($string);
    }

    public static function encodeUrl(?string $string): string
    {
        // Convert Base64 to Base64URL by replacing “+” with “-” and “/” with “_”
        $url = strtr(self::encode($string), '+/', '-_');
        // Remove padding character from the end of line
        return rtrim($url, '=');
    }

    public static function decodeUrl(?string $string): string
    {
        // Convert Base64URL to Base64 by replacing “-” with “+” and “_” with “/”
        $string = strtr($string ?? '', '-_', '+/');
        return self::decode($string);
    }
}
