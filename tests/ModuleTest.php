<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\Module;
use PHPUnit\Framework\TestCase;

class ModuleTest extends TestCase
{
    public function testModulesAreDiscoveredInDeterministicOrder(): void
    {
        $files = Module::findModulesInDir(__DIR__ . '/modules');
        $names = array_map(static fn(string $file): string => basename(dirname($file)), $files);

        $expected = $names;
        sort($expected);

        $this->assertSame($expected, $names);
        $this->assertNotEmpty($names);
    }
}
