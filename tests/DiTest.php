<?php

declare(strict_types=1);

namespace Kaly\Tests;

use PDO;
use Kaly\Di;
use Kaly\Util;
use PHPUnit\Framework\TestCase;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObject2;
use Kaly\Tests\Mocks\TestObject3;
use Kaly\Tests\Mocks\TestObject4;
use Kaly\Tests\Mocks\TestInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\ContainerExceptionInterface;

class DiTest extends TestCase
{
    public function testCreateObject()
    {
        $di = new Di();

        /** @var TestObject $TestObject  */
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

        /** @var TestObject2 $TestObject2  */
        $inst = $di->get(TestObject2::class);
        $this->assertInstanceOf(TestObject2::class, $inst);
        $this->assertEquals("somevalue", $inst->v);
    }

    public function testCreateObjectWithNamedArgs()
    {
        $di = new Di([
            TestObject2::class => ['v' => 'somevalue']
        ]);

        /** @var TestObject2 $TestObject2  */
        $inst = $di->get(TestObject2::class);
        $this->assertInstanceOf(TestObject2::class, $inst);
        $this->assertEquals("somevalue", $inst->v);
    }

    public function testCreateObjectWithDeps()
    {
        $di = new Di([
            TestObject2::class => ['v' => 'somevalue']
        ]);

        /** @var TestObject3 $TestObject3  */
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

        /** @var TestObject3 $inst  */
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

    public function testList()
    {
        $di = new Di([
            TestInterface::class => TestObject::class,
        ]);

        $this->assertContains(TestInterface::class, $di->listDefinitions());
    }

    public function testReturnItself()
    {
        $di = new Di();
        $this->assertEquals($di, $di->get(Di::class));
    }

    /**
     * Support capsule scenarios
     * @link https://github.com/capsulephp/comparison
     */
    public function testParametricalParams()
    {
        $def = [
            PDO::class => function () {
                $dsn = getenv("DB_DSN") ?? 'sqlite::memory:';
                $username =  getenv("DB_USERNAME") ?? 'root';
                $password =  getenv("DB_PASSWORD") ?? '';
                return new PDO($dsn, $username, $password);
            },
            TestObject4::class . ":bar" => function () {
                return 'bar-wrong';
            },
            TestObject4::class . ":baz" => function () {
                return 'baz-right';
            },
            TestObject4::class . ":arr" => ['one'],
            TestObject4::class . "->" => [
                'testMethod' => 'one',
                // note:  "val" must match parameter name
                'testMethod2' => ['val' => ['one']],
                // note: regular arrays are merged together
                'testMethod3' => ['one'],
                // calls wrapped in an array are queued instead of being merged
                [
                    'testQueue' => 'one',
                ]
            ],
        ];

        // Overload
        $def[TestObject4::class . ":bar"] = 'bar-right';

        // Merge array values
        $merge = [
            TestObject4::class . ":arr" => ['two'],
            TestObject4::class . "->" => [
                // this will replace 'one' by 'two'
                'testMethod' => 'two',
                // this will be merged together under the 'val' key
                // 'val' => ['one', 'two']
                'testMethod2' => ['val' => ['two'], 'other' => 'test'],
                // this will give ['one', 'two']
                'testMethod3' => ['two'],
                // this will call twice testQueue
                [
                    'testQueue' => 'two',
                ],
                // this will call a third time testQuue
                // it's always better to pass explicit list of arguments by name
                [
                    'testQueue' => [
                        'val' => 'three'
                    ],
                ],
            ],
        ];

        $def = array_merge_distinct($def, $merge, true);

        $container = new Di($def);

        putenv('DB_DSN=sqlite::memory:');
        putenv('DB_USERNAME=dbuser');
        putenv('DB_PASSWORD=dbpass');

        /** @var TestObject4 $foo  */
        $foo = $container->get(TestObject4::class);
        $this->assertEquals(PDO::class, get_class($foo->pdo));
        $this->assertEquals("bar-right", $foo->bar);
        $this->assertEquals("baz-right", $foo->baz);
        $this->assertNotEquals("baz-wrong", $foo->baz);
        $this->assertEquals(['one', 'two'], $foo->arr);

        // not queued
        $this->assertEquals(['two'], $foo->test);
        $this->assertNotEquals(['one', 'two'], $foo->test);

        $this->assertEquals(['one', 'two'], $foo->test2);
        $this->assertEquals('test', $foo->other);
        $this->assertEquals(['one', 'two'], $foo->test3);

        // queued
        $this->assertEquals(['one', 'two', 'three'], $foo->queue);
        $this->assertNotEquals(['two'], $foo->queue);
    }
}
