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
                throw new RedirectException($uri->withPath(RoutePath::withTrailingSlash($path)));
            }
        } elseif (str_ends_with($path, '/')) {
            throw new RedirectException($uri->withPath(RoutePath::withoutTrailingSlash($path)));
        }
    }

    /**
     * Replace a path segment, reapplying the trailing slash policy.
     *
     * Only the first whole-segment occurrence is replaced: removing the
     * leading locale of `/fr/shop/fr/` gives `/shop/fr/`, and canonicalizing
     * `/Shop/product/Shop/` gives `/shop/product/Shop/`.
     *
     * `Preserve` keeps the trailing slash state untouched.
     */
    public static function replaceSegment(
        ServerRequestInterface $request,
        string $remove,
        string $replace = '',
        TrailingSlash $trailingSlash = TrailingSlash::Preserve,
    ): UriInterface {
        $uri = $request->getUri();
        $path = $uri->getPath();
        $replacement = $replace !== '' ? '/' . $replace : '';
        if ($remove !== '') {
            $search = '/' . $remove;
            $offset = 0;
            while (($pos = strpos($path, $search, $offset)) !== false) {
                $end = $pos + strlen($search);
                if ($end === strlen($path) || $path[$end] === '/') {
                    $path = substr($path, 0, $pos) . $replacement . substr($path, $end);
                    break;
                }
                $offset = $pos + 1;
            }
        }
        return $uri->withPath(match ($trailingSlash) {
            TrailingSlash::Add => RoutePath::withTrailingSlash($path),
            // An emptied path is the root: '/fr' canonicalizes to '/', never ''
            TrailingSlash::Remove => $path === '' ? '/' : RoutePath::withoutTrailingSlash($path),
            TrailingSlash::Preserve => $path === '' ? '/' : $path,
        });
    }
}
