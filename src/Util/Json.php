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
 * The `*Relaxed` family accepts informal input — unquoted keys and
 * single-quoted strings, plus optional outer braces for a map — then
 * validates with the same strict decoder. It is a convenience for config
 * written by hand, not a second grammar: bare string values stay invalid
 * and trailing commas still fail.
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
        return self::assertList($json, self::decode($json));
    }

    /**
     * @return array<string, mixed>
     *
     * A JSON object with numeric-string keys ({"0": "x"}) decodes to int
     * keys and is rejected: it is not representable as our map shape.
     */
    public static function decodeMap(string $json): array
    {
        return self::assertMap($json, self::decode($json));
    }

    /**
     * Informal input decoded by the strict decoder after a small relax
     * step: unquoted keys and single-quoted strings are normalized,
     * everything else is left for `json_decode` to judge. Valid JSON is
     * never rewritten.
     */
    public static function decodeRelaxed(string $text): mixed
    {
        return self::decodeLenient($text)[1];
    }

    /** @return list<mixed> */
    public static function decodeListRelaxed(string $text): array
    {
        return self::assertList(...self::decodeLenient($text));
    }

    /**
     * A relaxed map for informal config: `{a: 1}` or even `a: 1, b: 'x'`.
     * An empty input is an empty config, never an error.
     *
     * @return array<string, mixed>
     */
    public static function decodeMapRelaxed(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        try {
            $value = self::decode($text);
            $candidate = $text;
        } catch (JsonException) {
            // A map may omit its braces entirely
            $candidate = self::relax($text);
            if (!str_starts_with($candidate, '{')) {
                $candidate = '{' . $candidate . '}';
            }
            $value = self::decode($candidate);
        }

        return self::assertMap($candidate, $value);
    }

    public static function validate(string $json): bool
    {
        return json_validate($json);
    }

    /**
     * Strict first, then relaxed: the decoded value and the text it was
     * actually decoded from, so shape checks stay honest.
     *
     * @return array{0:string,1:mixed}
     */
    private static function decodeLenient(string $text): array
    {
        try {
            return [$text, self::decode($text)];
        } catch (JsonException) {
            $relaxed = self::relax($text);
            return [$relaxed, self::decode($relaxed)];
        }
    }

    /**
     * Normalize informal input to strict JSON: unquoted keys and
     * single-quoted strings, nothing else. Deliberately not a parser — a
     * quoted value containing a `word:` pattern does not survive the
     * transform and the final decode then rejects, which is the price of
     * keeping the relaxed subset tiny.
     */
    private static function relax(string $text): string
    {
        return preg_replace_callback(
            "/([a-zA-Z_\$][\\w$-]*)\\s*:|'((?:\\\\'|[^'])*)'/",
            static function (array $m): string {
                if ($m[1] !== '') {
                    return '"' . $m[1] . '":';
                }
                // A single-quoted string: restore \' escapes, then
                // neutralize double quotes for the JSON result
                $unescaped = str_replace("\\'", "'", $m[2]);
                return '"' . str_replace('"', '\\"', $unescaped) . '"';
            },
            $text,
        ) ?? $text;
    }

    /**
     * @return list<mixed>
     */
    private static function assertList(string $candidate, mixed $value): array
    {
        // An empty JSON object decodes like an empty array, so the raw shape
        // is what tells them apart: `{"0": "x"}` is not a list either
        if (self::shape($candidate) !== '[' || !is_array($value) || !array_is_list($value)) {
            throw new JsonException('Expected a JSON array');
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private static function assertMap(string $candidate, mixed $value): array
    {
        if (self::shape($candidate) !== '{' || !is_array($value)) {
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
