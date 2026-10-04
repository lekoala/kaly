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

    public static function pretty(mixed $value): string
    {
        return self::encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
     * @return array<array-key, mixed>
     *
     * PHP converts integer-string object keys ({"0": "x"}) to int keys.
     */
    public static function decodeMap(string $json): array
    {
        return self::assertMap($json, self::decode($json));
    }

    /**
     * Informal input decoded by the strict decoder after a small relax
     * step: unquoted keys and single-quoted strings are normalized,
     * everything else is left for `json_decode` to judge.
     *
     * The first meaningful character decides the path: `[`/`{` claim a
     * real container and get strict first (valid JSON is never rewritten),
     * relaxed only on failure. Anything else is scalar-or-informal input
     * and goes through relax directly.
     */
    public static function decodeRelaxed(string $text): mixed
    {
        $first = ltrim($text)[0] ?? '';
        if ($first === '[' || $first === '{') {
            try {
                return self::decode($text);
            } catch (JsonException) {
                // A container-shaped failure gets its chance relaxed
                // @mago-expect lint:no-empty-catch-clause
            }
        }
        return self::decode(self::relax($text));
    }

    /** @return list<mixed> */
    public static function decodeListRelaxed(string $text): array
    {
        return self::assertList($text, self::decodeRelaxed($text));
    }

    /**
     * A relaxed map for informal config: `{a: 1}` or even `a: 1, b: 'x'`.
     * An empty input is an empty config, never an error.
     *
     * @return array<array-key, mixed>
     */
    public static function decodeMapRelaxed(string $text): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }
        // Not container-shaped: the informal map, `a: 1, b: 'x'`
        if ($text[0] !== '{' && $text[0] !== '[') {
            $text = '{' . self::relax($text) . '}';
            return self::assertMap($text, self::decode($text));
        }
        return self::assertMap($text, self::decodeRelaxed($text));
    }

    public static function validate(string $json): bool
    {
        return json_validate($json);
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
        if (!str_starts_with(ltrim($candidate), '[') || !is_array($value) || !array_is_list($value)) {
            throw new JsonException('Expected a JSON array');
        }

        return $value;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function assertMap(string $candidate, mixed $value): array
    {
        if (!str_starts_with(ltrim($candidate), '{') || !is_array($value)) {
            throw new JsonException('Expected a JSON object');
        }
        return $value;
    }
}
