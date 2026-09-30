<?php

declare(strict_types=1);

namespace Kaly\I18n;

use RuntimeException;

/**
 * Stateless locale helpers.
 *
 * Parsing and validating a locale string belongs here, not on a translator
 * implementation: the locale is resolved from the request (routing, headers)
 * before any translation happens.
 */
final class Locale
{
    // ISO 639 2 or 3, or 4 for future use, alpha
    public const LANGUAGE = 'language';
    // ISO 15924 4 alpha
    public const SCRIPT = 'script';
    // ISO 3166-1 2 alpha or 3 digit
    public const COUNTRY = 'country';
    public const PRIVATE = 'private';

    /**
     * @return array<string, string>
     */
    public static function parse(string $locale): array
    {
        $languagePart = '(?<language>[A-Za-z]{2,4})';
        $scriptPart = '([_-](?<script>[A-Za-z]{4}|[0-9]{3}))?';
        $countryPart = '([_-](?<country>[A-Za-z]{2}|[0-9]{3}))?';
        $privatePart = '([_-]x[_-](?<private>[A-Za-z0-9-_]+))';
        $pattern = "/^{$languagePart}{$scriptPart}{$countryPart}{$privatePart}?$/";
        $matches = [];
        $results = preg_match($pattern, $locale, $matches);
        if (!$results) {
            throw new RuntimeException("Failed to parse locale string '{$locale}'");
        }
        $matches = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        $matches['script'] ??= '';
        $matches['country'] ??= '';
        $matches['private'] ??= '';
        return $matches;
    }

    public static function language(string $locale): string
    {
        return strtolower(explode('-', str_replace('_', '-', $locale), 3)[0]);
    }

    public static function isValid(string $locale): bool
    {
        try {
            self::parse($locale);
        } catch (RuntimeException) {
            return false;
        }
        return true;
    }
}
