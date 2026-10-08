<?php

declare(strict_types=1);

namespace Kaly\Asset;

/**
 * The asset url generator exposed to a render.
 *
 * It is the view-facing face of {@see AssetsInterface}, kept separate so the
 * render environment carries a typed capability instead of a raw closure.
 */
final readonly class AssetView
{
    public function __construct(
        private AssetsInterface $assets,
    ) {}

    public function __invoke(string $asset): string
    {
        return $this->assets->url($asset);
    }
}
