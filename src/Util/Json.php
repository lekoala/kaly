<?php

declare(strict_types=1);

namespace Kaly\Util;

use JsonException;

/**
 * The JSON boundary: one place for encode/decode, strict by contract —
 * malformed input or the wrong outer shape throws, never falls back
 * silently. `decodeMap()`/`decodeList()` also give static analysis the outer
 * structure so callers stop negotiating with `mixed` immediately.
 * Field-level narrowing on decoded values belongs to `Types`.
 *
 * @link https://wiki.php.net/rfc/json_throw_on_error
 */
final class Json
{
    public static function encode(mixed $value, int $flags = 0): string
    {
        return json_encode($value, $flags | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    public static function decode(string $json): mixed
    {
        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<mixed> */
    public static function decodeList(string $json): array
    {
        $value = self::decode($json);
        // An empty JSON object decodes like an empty array, so the raw shape
        // is what tells them apart: `{"0": "x"}` is not a list either
        if (self::shape($json) !== '[' || !is_array($value) || !array_is_list($value)) {
            throw new JsonException('Expected a JSON array');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     *
     * A JSON object with numeric-string keys ({"0": "x"}) decodes to int
     * keys and is rejected: it is not representable as our map shape.
     */
    public static function decodeMap(string $json): array
    {
        $value = self::decode($json);
        if (self::shape($json) !== '{' || !is_array($value)) {
            throw new JsonException('Expected a JSON object');
        }
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new JsonException('Expected a JSON object');
            }
        }

        /** @var array<string, mixed> $value verified above */
        return $value;
    }

    public static function validate(string $json): bool
    {
        return json_validate($json);
    }

    /**
     * The first meaningful character of a JSON document: `{`, `[` or anything
     * else for a scalar. `json_decode()` erases the array/object distinction
     * on empty containers and numeric-string keys, this keeps it.
     */
    private static function shape(string $json): string
    {
        $trimmed = ltrim($json, " \t\n\r\0\x0B");
        return $trimmed === '' ? '' : $trimmed[0];
    }
}
