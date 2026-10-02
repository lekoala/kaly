<?php

declare(strict_types=1);

namespace Kaly\Http;

/**
 * A parsed media type: `type/subtype` and its parameters.
 *
 * Parsing is quote-aware, so a parameter may legitimately contain the
 * separators: `multipart/form-data; boundary="a;b"` keeps `a;b` as the
 * boundary, and a parameter without a value is kept as an empty string rather
 * than reading past the end of the part.
 */
final class MediaType
{
    /**
     * @param array<string,string> $params Lowercase parameter names, unquoted values
     */
    private function __construct(
        public readonly string $type,
        public readonly string $subtype,
        public readonly array $params,
    ) {}

    /**
     * Parse a single media type, or null when the header carries no type at all.
     */
    public static function parse(?string $header): ?self
    {
        if ($header === null) {
            return null;
        }
        $parts = self::splitOn($header, ';');
        $name = self::normalizeName(array_shift($parts));
        if ($name === '') {
            return null;
        }

        $separator = strpos($name, '/');
        $type = $separator === false ? $name : substr($name, 0, $separator);
        $subtype = $separator === false ? '' : substr($name, $separator + 1);

        $params = [];
        foreach ($parts as $part) {
            $eq = strpos($part, '=');
            if ($eq === false) {
                // A bare parameter name: keep it, with an empty value
                $bareName = self::normalizeName($part);
                if ($bareName !== '') {
                    $params[$bareName] = '';
                }
                continue;
            }
            $key = self::normalizeName(substr($part, 0, $eq));
            if ($key === '') {
                continue;
            }
            $params[$key] = self::unquote(trim(substr($part, $eq + 1)));
        }

        return new self($type, $subtype, $params);
    }

    /**
     * Does this media type accept the given concrete type?
     *
     * Wildcards match at both levels, and a subtype starting with '+' is a
     * structured suffix: "application/problem+json" accepts the "+json" family.
     */
    public function accepts(string $type, string $subtype): bool
    {
        $type = strtolower($type);
        $subtype = strtolower($subtype);
        if ($this->type !== '*' && $this->type !== $type) {
            return false;
        }
        if ($this->subtype === '*' || $subtype === '*') {
            return true;
        }
        if ($this->subtype === $subtype) {
            return true;
        }
        // The caller asks about a structured family, eg: '+json'
        return str_starts_with($subtype, '+') && str_ends_with($this->subtype, $subtype);
    }

    /**
     * Does this media type carry a type at all?
     */
    public function isEmpty(): bool
    {
        return $this->type === '' && $this->subtype === '';
    }

    /**
     * Is this the "anything" entry, which expresses no preference of its own?
     */
    public function isWildcard(): bool
    {
        return $this->type === '*';
    }

    /**
     * How specific this entry is, from 0 (any type) to 2 (explicit).
     *
     * Used to let an explicit `text/html` outrank `text/*` when the client
     * gave both the same weight.
     */
    public function specificity(): int
    {
        if ($this->type === '*') {
            return 0;
        }
        return $this->subtype === '*' || $this->subtype === '' ? 1 : 2;
    }

    /**
     * The full type/subtype, eg: `application/json`.
     */
    public function __toString(): string
    {
        return $this->subtype === '' ? $this->type : $this->type . '/' . $this->subtype;
    }

    /**
     * Split a header on a single delimiter, ignoring delimiters inside quoted
     * strings, and honouring backslash escapes within them.
     *
     * `;` and `,` are the delimiters media type and Accept parsing need.
     *
     * @return list<string>
     */
    public static function splitOn(string $header, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $quoted = false;
        $length = strlen($header);
        for ($i = 0; $i < $length; $i++) {
            $char = $header[$i];
            if ($char === '\\' && $quoted && ($i + 1) < $length) {
                $current .= $char . $header[++$i];
                continue;
            }
            if ($char === '"') {
                $quoted = !$quoted;
                $current .= $char;
                continue;
            }
            if (!$quoted && $char === $delimiter) {
                $parts[] = $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $parts[] = $current;
        return $parts;
    }

    /**
     * Normalize a media type name, parameter name or content type token for
     * case-insensitive comparison: lowercase, with the optional whitespace
     * around header list separators stripped.
     */
    public static function normalizeName(?string $value): string
    {
        return strtolower(trim($value ?? ''));
    }

    private static function unquote(string $value): string
    {
        if (strlen($value) >= 2 && str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return str_replace('\\"', '"', substr($value, 1, -1));
        }
        return $value;
    }
}
