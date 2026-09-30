<?php

declare(strict_types=1);

namespace Kaly\Asset;

use RuntimeException;

/**
 * The `asset` template variable when no AssetsInterface is bound: the
 * variable exists, but calling it explains what is missing instead of
 * failing later on an unrelated publish error.
 */
final class NullAssets implements AssetsInterface
{
    public function url(string $asset): string
    {
        throw new RuntimeException("Cannot produce the url of asset '{$asset}': no " . AssetsInterface::class . ' is bound');
    }
}
