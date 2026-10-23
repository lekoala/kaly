<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Http\Exception\RedirectException;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Canonical url redirects enforced by the router.
 *
 * Redirects keep a single canonical url per resource (trailing slash policy,
 * lowercase segments) so duplicates never reach the dispatcher.
 *
 * Locations are relative references (path and query): the request Host and
 * scheme are client input, and reflecting them in a cacheable redirect would
 * let a forged Host poison a shared cache.
 */
final class RedirectUris
{
    /**
     * Enforce the trailing slash policy, redirecting when the path disagrees.
     *
     * `/` is a fixed point: it never redirects. `Preserve` never redirects.
     *
     * @throws RedirectException
     */
    public static function ensureTrailingSlash(ServerRequestInterface $request, TrailingSlash $policy): void
    {
        if ($policy === TrailingSlash::Preserve) {
            return;
        }
        $uri = $request->getUri();
        $path = $uri->getPath();
        if ($path === '/') {
            return;
        }
        if ($policy === TrailingSlash::Add) {
            if (!str_ends_with($path, '/')) {
                throw new RedirectException(self::local($uri, RoutePath::withTrailingSlash($path)));
            }
        } elseif (str_ends_with($path, '/')) {
            throw new RedirectException(self::local($uri, RoutePath::withoutTrailingSlash($path)));
        }
    }

    /**
     * Replace a path segment, reapplying the trailing slash policy.
     *
     * The first whole-segment occurrence at or after $segmentOffset is replaced: removing the
     * leading locale of `/fr/shop/fr/` gives `/shop/fr/`, and canonicalizing
     * `/Shop/product/Shop/` gives `/shop/product/Shop/`.
     *
     * `Preserve` keeps the trailing slash state untouched. The result is a
     * relative reference, see self::local().
     */
    public static function replaceSegment(
        ServerRequestInterface $request,
        string $remove,
        string $replace = '',
        TrailingSlash $trailingSlash = TrailingSlash::Preserve,
        int $segmentOffset = 0,
    ): UriInterface {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $replacement = $replace !== '' ? '/' . $replace : '';
        if ($remove !== '') {
            $search = '/' . $remove;
            preg_match_all('#/[^/]+#', $path, $segments, PREG_OFFSET_CAPTURE);
            $offset = $segments[0][$segmentOffset][1] ?? strlen($path);
            while (($pos = strpos($path, $search, $offset)) !== false) {
                $end = $pos + strlen($search);
                if ($end === strlen($path) || $path[$end] === '/') {
                    $path = substr($path, 0, $pos) . $replacement . substr($path, $end);
                    break;
                }
                $offset = $pos + 1;
            }
        }
        return self::local($uri, match ($trailingSlash) {
            TrailingSlash::Add => RoutePath::withTrailingSlash($path),
            // An emptied path is the root: '/fr' canonicalizes to '/', never ''
            TrailingSlash::Remove => $path === '' ? '/' : RoutePath::withoutTrailingSlash($path),
            TrailingSlash::Preserve => $path === '' ? '/' : $path,
        });
    }

    public static function toPath(ServerRequestInterface $request, string $path, TrailingSlash $policy): UriInterface
    {
        $path = match ($policy) {
            TrailingSlash::Add => RoutePath::withTrailingSlash($path),
            TrailingSlash::Remove => $path === '' ? '/' : RoutePath::withoutTrailingSlash($path),
            TrailingSlash::Preserve => $path === '' ? '/' : $path,
        };
        return self::local($request->getUri(), $path);
    }

    /**
     * The relative reference of a path on this uri, keeping its query.
     *
     * Leading slashes collapse to one: without an authority, `//evil.test/`
     * (or `/\evil.test/`, which browsers read the same way) would be a
     * network-path reference to another host.
     */
    private static function local(UriInterface $uri, string $path): UriInterface
    {
        return $uri
            ->withScheme('')
            ->withUserInfo('')
            ->withHost('')
            ->withPort(null)
            ->withPath('/' . ltrim($path, '/\\'));
    }
}
