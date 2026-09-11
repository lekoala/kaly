<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use Kaly\Http\RequestInput;

final readonly class SaveInput implements RequestInput
{
    public function __construct(
        public string $test,
    ) {}
}
