<?php

declare(strict_types=1);

namespace Kaly\Interfaces;

interface FaviconProviderInterface
{
    public function getSvgIcon(): string;
}
