<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use Kaly\Http\Input\ValidatableInput;
use Kaly\Validation\Validator;

final readonly class PaginationInput implements ValidatableInput
{
    public function __construct(
        public int $page = 1,
    ) {}

    public function validate(Validator $validator): void
    {
        $validator->between('page', $this->page, min: 1);
    }
}
