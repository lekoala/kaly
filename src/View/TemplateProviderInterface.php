<?php

declare(strict_types=1);

namespace Kaly\View;

interface TemplateProviderInterface
{
    public function getTemplate(): string;
}
