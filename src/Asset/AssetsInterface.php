<?php

declare(strict_types=1);

namespace Kaly\Asset;

/**
 * Knows where the browser-ready assets of the application live and produces
 * their public URL.
 *
 * Asset directories contain browser-ready files. Kaly preserves their
 * relative paths and contents. It never parses, compiles, bundles, minifies,
 * rewrites, or resolves frontend dependencies.
 */
interface AssetsInterface
{
    /**
     * Produce the public URL of an asset: `app.css` (the `app` namespace) or
     * `@admin/admin.js` (a module namespace).
     */
    public function url(string $asset): string;
}
