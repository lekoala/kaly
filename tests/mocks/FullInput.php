<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use Kaly\Http\RequestInput;

final readonly class FullInput implements RequestInput
{
    /**
     * @param array<mixed> $tags
     */
    public function __construct(
        public string $name,
        public int $page = 1,
        public float $ratio = 0.5,
        public bool $active = false,
        public array $tags = [],
        public ?PatientStatus $status = null,
        public ?Priority $priority = null,
    ) {}
}
