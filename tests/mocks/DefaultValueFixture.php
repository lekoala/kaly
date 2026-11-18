<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

/**
 * Two actions sharing one path: the GET action has a default object, which
 * routing must not construct while checking another method.
 */
final class DefaultValueFixture
{
    public static int $constructed = 0;

    public function read(mixed $option = new DefaultValueOption()): string
    {
        return $option instanceof DefaultValueOption ? 'read' : 'unexpected';
    }

    public function write(): string
    {
        return 'write';
    }
}

final class DefaultValueOption
{
    public function __construct()
    {
        DefaultValueFixture::$constructed++;
    }
}
