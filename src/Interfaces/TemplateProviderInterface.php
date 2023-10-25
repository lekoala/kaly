<?php

declare(strict_types=1);

namespace Kaly\Interfaces;

interface TemplateProviderInterface
{
    public function getTemplate(): string;
}
