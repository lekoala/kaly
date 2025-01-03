<?php

declare(strict_types=1);

namespace Kaly\Util;

use JsonException;
use RuntimeException;
use stdClass;

/**
 * Typesafe json utilities
 *
 * @link https://github.com/nette/utils/blob/master/src/Utils/Json.php
 * @link https://wiki.php.net/rfc/json_throw_on_error
 */
final class Json
{
    /**
     * @param array<mixed>|string|object $value
     * @param int<0, max> $flags
     * @param int<1, max> $depth
     * @return string
     */
    public static function encode(array|string|object $value, int $flags = 0, int $depth = 512): string
    {
        if (is_string($value)) {
            return $value;
        }
        // Use safer defaults
        if ($flags === 0) {
            $flags = JSON_INVALID_UTF8_SUBSTITUTE;
        }
        $result = json_encode($value, $flags, $depth);
        if ($result === false) {
            throw new JsonException(json_last_error_msg(), json_last_error());
        }
        return $result;
    }

    public static function decode(?string $value = null, bool $assoc = true): mixed
    {
        if ($value === null) {
            $value = "[]";
        }
        $result = json_decode($value, $assoc);
        if ($result === null) {
            throw new JsonException(json_last_error_msg(), json_last_error());
        }
        return $result;
    }

    /**
     * @return array<mixed>
     */
    public static function decodeArr(?string $value = null): array
    {
        if (!$value) {
            return [];
        }
        $res = self::decode($value, true);
        if (!is_array($res)) {
            throw new RuntimeException("Decoded value is not an array");
        }
        return $res;
    }

    /**
     * @return object
     */
    public static function decodeObj(?string $value = null): object
    {
        if (!$value) {
            return new stdClass();
        }
        $res = self::decode($value, false);
        if (!is_object($res)) {
            throw new RuntimeException("Decoded value is not an object");
        }
        return $res;
    }

    /**
     * @param string|null $string
     * @param int<0, max> $flags
     * @param int $depth
     * @return bool
     */
    public static function validate(?string $string = null, int $flags = 0, int $depth = 512): bool
    {
        if (!$string) {
            return false;
        }
        //@phpstan-ignore-next-line
        return json_validate($string, $flags, $depth);
    }
}
