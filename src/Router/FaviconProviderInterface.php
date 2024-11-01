<?php

declare(strict_types=1);

namespace Kaly\Router;

interface FaviconProviderInterface
{
    /**
     * <link rel="icon" href="favicon.svg">
     * @return string
     */
    public function getSvgIcon(): string;
}
