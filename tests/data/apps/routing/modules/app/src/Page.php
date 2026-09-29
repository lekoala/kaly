<?php

declare(strict_types=1);

namespace App;

final readonly class Page
{
    public function __construct(
        public string $title,
    ) {}
}
