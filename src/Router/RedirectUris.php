<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Http\RedirectException;
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
     * @throws RedirectException
     */
    public static function ensureTrailingSlash(ServerRequestInterface $request, bool $force): void
    {
        $uri = $request->getUri();
        $path = $uri->getPath();
        if ($force) {
            if (!str_ends_with($path, '/')) {
                throw new RedirectException($uri->withPath($path . '/'));
            }
        } elseif (str_ends_with($path, '/')) {
            throw new RedirectException($uri->withPath(rtrim($path, '/')));
        }
    }

    /**
     * Replace a path segment, reapplying the trailing slash policy.
     *
     * Only the first whole-segment occurrence is replaced: removing the
     * leading locale of `/fr/shop/fr/` gives `/shop/fr/`, and canonicalizing
     * `/Shop/product/Shop/` gives `/shop/product/Shop/`.
     */
    public static function replaceSegment(
        ServerRequestInterface $request,
        string $remove,
        string $replace = '',
        bool $forceTrailingSlash = true,
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
        $path = rtrim($path, '/');
        if ($forceTrailingSlash) {
            $path .= '/';
        }
        return $uri->withPath($path);
    }
}
