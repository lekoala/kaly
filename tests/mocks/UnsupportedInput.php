<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use DateTimeImmutable;
use Kaly\Http\RequestInput;

final readonly class UnsupportedInput implements RequestInput
{
    public function __construct(
        public DateTimeImmutable $when,
    ) {}
}
