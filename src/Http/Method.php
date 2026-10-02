<?php

declare(strict_types=1);

namespace Kaly\Http;

final class Method
{
    public const GET = 'GET';
    public const POST = 'POST';
    public const PUT = 'PUT';
    public const DELETE = 'DELETE';
    public const OPTIONS = 'OPTIONS';
    public const PATCH = 'PATCH';
    public const HEAD = 'HEAD';
    // Classified but intentionally not routable: kept out of ALL.
    public const TRACE = 'TRACE';

    public const ALL = [
        self::GET,
        self::POST,
        self::PUT,
        self::DELETE,
        self::PATCH,
        self::HEAD,
        self::OPTIONS,
    ];

    /**
     * The canonical form of an HTTP method: uppercase.
     */
    public static function normalize(string $method): string
    {
        return strtoupper($method);
    }

    /**
     * Normalize a list of methods, dropping duplicates while keeping the
     * first occurrence order.
     *
     * @param iterable<string> $methods
     * @return list<string>
     */
    public static function normalizeList(iterable $methods): array
    {
        $normalized = [];
        foreach ($methods as $method) {
            $method = self::normalize($method);
            if (!in_array($method, $normalized, true)) {
                $normalized[] = $method;
            }
        }
        return $normalized;
    }

    /**
     * Is the method safe, per RFC 9110: essentially read-only.
     *
     * Comparison is case-sensitive, per HTTP: 'get' returns false.
     * An unknown method returns false, as its properties are not known.
     */
    public static function isSafe(string $method): bool
    {
        return match ($method) {
            self::GET, self::HEAD, self::OPTIONS, self::TRACE => true,
            default => false,
        };
    }

    /**
     * Is the method idempotent, per RFC 9110: the same intended effect
     * after several identical calls, not necessarily the same response.
     *
     * Comparison is case-sensitive, per HTTP: 'get' returns false.
     * An unknown method returns false, as its properties are not known.
     */
    public static function isIdempotent(string $method): bool
    {
        return self::isSafe($method) || $method === self::PUT || $method === self::DELETE;
    }
}
