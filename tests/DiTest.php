<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di;
use PHPUnit\Framework\TestCase;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObject2;
use Kaly\Tests\Mocks\TestObject3;
use Kaly\Tests\Mocks\TestInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;

class DiTest extends TestCase
{
    public function testCreateObject()
    {
        $di = new Di();

        $inst = $di->get(TestObject::class);
        $this->assertInstanceOf(TestObject::class, $inst);
    }

    public function testCreateObjectFromClosure()
    {
        $di = new Di([
            'cl' => function (ContainerInterface $di) {
                return new TestObject();
            }
        ]);

        $inst = $di->get('cl');
        $this->assertInstanceOf(TestObject::class, $inst);
    }

    public function testCreateObjectWithArgs()
    {
        $di = new Di([
            TestObject2::class => ['somevalue']
        ]);

        $inst = $di->get(TestObject2::class);
        $this->assertInstanceOf(TestObject2::class, $inst);
        $this->assertEquals("somevalue", $inst->v);
    }

    public function testCreateObjectWithNamedArgs()
    {
        $di = new Di([
            TestObject2::class => ['v' => 'somevalue']
        ]);

        $inst = $di->get(TestObject2::class);
        $this->assertInstanceOf(TestObject2::class, $inst);
        $this->assertEquals("somevalue", $inst->v);
    }

    public function testCreateObjectWithDeps()
    {
        $di = new Di([
            TestObject2::class => ['v' => 'somevalue']
        ]);

        $inst = $di->get(TestObject3::class);
        $this->assertInstanceOf(TestObject3::class, $inst);
        $this->assertEquals("somevalue", $inst->obj2->v);
        $this->assertEquals('default', $inst->optional);
    }

    public function testCreateObjectWithAlias()
    {
        $di = new Di([
            'obj1' => function (): TestObject {
                return new TestObject();
            },
            'obj2' => function (): TestObject2 {
                return new TestObject2('somevalue');
            }
        ]);

        $inst = $di->get(TestObject3::class);
        $this->assertInstanceOf(TestObject3::class, $inst);
        $this->assertEquals("somevalue", $inst->obj2->v);
        $this->assertEquals('default', $inst->optional);
    }

    public function testInterfaceBinding()
    {
        $di = new Di([
            TestInterface::class => TestObject::class,
        ]);

        $inst = $di->get(TestInterface::class);
        $this->assertInstanceOf(TestObject::class, $inst);
    }

    public function testInvalidDefinition()
    {
        $di = new Di([
            'db.host' => 'localhost',
        ]);

        $this->expectException(ContainerExceptionInterface::class);
        $inst = $di->get('db.host');
    }
}
