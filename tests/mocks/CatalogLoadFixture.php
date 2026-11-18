<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

/**
 * Counts how many times a generated catalog file is required.
 */
final class CatalogLoadFixture
{
    public static int $loads = 0;
}
