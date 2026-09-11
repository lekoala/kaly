<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use Kaly\Http\ValidatableInput;
use Kaly\Http\ValidationException;

final readonly class PaginationInput implements ValidatableInput
{
    public function __construct(
        public int $page = 1,
    ) {}

    public function validate(): void
    {
        if ($this->page < 1) {
            throw new ValidationException('page must be >= 1');
        }
    }
}
