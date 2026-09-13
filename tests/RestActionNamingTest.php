<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Router\RestActionNaming;
use PHPUnit\Framework\TestCase;

class RestActionNamingTest extends TestCase
{
    public function testMethodSuffix(): void
    {
        $this->assertSame('Post', RestActionNaming::methodSuffix('POST'));
        $this->assertSame('Get', RestActionNaming::methodSuffix('get'));
        $this->assertSame('Delete', RestActionNaming::methodSuffix('DELETE'));
    }

    public function testVerbSuffix(): void
    {
        $this->assertSame('Post', RestActionNaming::verbSuffix('changePost'));
        $this->assertSame('Get', RestActionNaming::verbSuffix('listGet'));
        $this->assertNull(RestActionNaming::verbSuffix('index'));
        $this->assertNull(RestActionNaming::verbSuffix(''));
    }

    public function testStripVerbSuffix(): void
    {
        $this->assertSame('change', RestActionNaming::stripVerbSuffix('changePost'));
        $this->assertSame('index', RestActionNaming::stripVerbSuffix('index'));
    }
}
