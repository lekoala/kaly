<?php

declare(strict_types=1);

namespace Kaly;

use Kaly\Interfaces\FaviconProviderInterface;

class SiteConfig implements FaviconProviderInterface
{
    public const ICON_SQUARE = 0;
    public const ICON_ROUNDED_SQUARE = 20;
    public const ICON_ROUND = 50;

    protected string $themeColor = "#000000";
    protected string $siteIcon = "";
    protected int $iconRounding = 50;
    protected int $iconSize = 80;

    public function getSvgIcon(): string
    {
        $svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 100 100">
    <rect width="100" height="100" rx="{$this->iconRounding}" fill="{$this->themeColor}"></rect>
    <text x="50%" y="50%" dominant-baseline="central" text-anchor="middle" font-size="{$this->iconSize}">{$this->siteIcon}</text>
</svg>
SVG;
        return $svg;
    }

    public function getThemeColor(): string
    {
        return $this->themeColor;
    }

    public function setThemeColor(string $themeColor): self
    {
        $this->themeColor = $themeColor;
        return $this;
    }

    public function getSiteIcon(): string
    {
        return $this->siteIcon;
    }

    public function setSiteIcon(string $siteIcon): self
    {
        $this->siteIcon = $siteIcon;
        return $this;
    }

    public function getIconRounding(): int
    {
        return $this->iconRounding;
    }

    public function setIconRounding(int $iconRounding): self
    {
        $this->iconRounding = $iconRounding;
        return $this;
    }

    public function getIconSize(): int
    {
        return $this->iconSize;
    }

    public function setIconSize(int $iconSize): self
    {
        $this->iconSize = $iconSize;
        return $this;
    }
}
