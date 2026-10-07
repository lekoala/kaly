<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Util\Arr;

/**
 * Path helpers for Kaly routing semantics.
 *
 * A route path is a list of logical segments: leading, trailing and repeated
 * slashes do not create segments, so '/foo//bar/' is ['foo', 'bar'] and '/'
 * is [].
 *
 * This is not an RFC 3986 URI normalizer: percent-encoding and dot-segments
 * are left untouched. It is also unrelated to filesystem path handling.
 */
final class RoutePath
{
    /**
     * The non-empty segments of a route path.
     *
     * @return list<string>
     */
    public static function segments(string $path): array
    {
        return Arr::filterList(explode('/', $path), static fn(string $segment): bool => $segment !== '');
    }

    /**
     * Join route path parts with single slashes and a leading slash, without a
     * trailing slash except for the root path — unless the last part carries
     * one, which is preserved so explicit declarations keep their spelling.
     */
    public static function join(string ...$parts): string
    {
        $segments = [];
        foreach ($parts as $part) {
            foreach (self::segments($part) as $segment) {
                $segments[] = $segment;
            }
        }
        $path = '/' . implode('/', $segments);
        $last = end($parts);
        if ($last !== false && $path !== '/' && str_ends_with($last, '/')) {
            $path .= '/';
        }
        return $path;
    }

    /**
     * Ensure the path ends with a slash.
     */
    public static function withTrailingSlash(string $path): string
    {
        return rtrim($path, '/') . '/';
    }

    /**
     * Strip the trailing slash, keeping the root path '/' intact.
     */
    public static function withoutTrailingSlash(string $path): string
    {
        if ($path === '/') {
            return '/';
        }
        return rtrim($path, '/');
    }
}
