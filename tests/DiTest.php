<?php

declare(strict_types=1);

namespace Kaly\Tests;

use PDO;
use Kaly\Di;
use Kaly\App;
use Kaly\Http;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObject2;
use Kaly\Tests\Mocks\TestObject3;
use Kaly\Tests\Mocks\TestObject4;
use Kaly\Tests\Mocks\TestInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
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
        // obj1 and obj2 are the names of the variable in the TestObject3 constructor
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
            // aliases should be properly expanded to closure result
            TestObject::class => function (): TestObject {
                $inst = new TestObject();
                $inst->setVal("closure");
                return $inst;
            }
        ]);

        /** @var TestObject $inst  */
        $inst = $di->get(TestInterface::class);
        $this->assertInstanceOf(TestObject::class, $inst);
        $this->assertEquals("closure", $inst->val);

        $inst->setVal("updated");

        // aliases are generating underlying cache as well if bound to a class
        /** @var TestObject $inst  */
        $inst = $di->get(TestObject::class);
        $this->assertInstanceOf(TestObject::class, $inst);
        $this->assertEquals("updated", $inst->val);

        // Test invalid binding
        $di = new Di([
            TestInterface::class => TestObject2::class,
            TestObject2::class => ['somevalue'],
        ]);

        $this->expectException(ContainerExceptionInterface::class);
        $inst = $di->get(TestInterface::class);
    }

    public function testFactory()
    {
        $counter = 0;
        $di = new Di([
            TestObject::class => function () use (&$counter): TestObject {
                $inst = new TestObject();
                $counter++;
                $inst->setCounter($counter);
                return $inst;
            }
        ]);

        /** @var TestObject $inst  */
        $inst = $di->get(TestObject::class . ':new');
        $inst2 = $di->get(TestObject::class . ':new');
        $this->assertNotEquals($inst->getCounter(), $inst2->getCounter());
    }

    public function testCallable()
    {
        $app = new App(__DIR__);
        $di = new Di([
            App::class => $app,
            // Bound to function
            ServerRequestInterface::class => fn () => Http::createRequestFromGlobals()
        ], [
            ServerRequestInterface::class
        ]);

        // We need to handle a request before
        $app->boot();

        $request = Http::createRequestFromGlobals();
        $request = $request->withUri(new Uri("/uri1"));
        $app->handle($request);

        $inst = $app->getRequest();

        $this->assertInstanceOf(ServerRequestInterface::class, $inst);

        $request = $request->withUri(new Uri("/uri2"));
        $app->handle($request);

        $inst2 =  $app->getRequest();

        // This should not be cached
        $this->assertNotEquals($inst->getUri()->getPath(), $inst2->getUri()->getPath());
    }

    public function testInvalidDefinition()
    {
        $di = new Di([
            'db.host' => 'localhost',
        ]);

        $this->expectException(ContainerExceptionInterface::class);
        $inst = $di->get('db.host');
    }

    public function testReturnItself()
    {
        $di = new Di();
        $this->assertEquals($di, $di->get(Di::class));
    }

    public function testStrictDefinitions()
    {
        $def = [
            TestInterface::class => TestObject::class,
            // Pass null if "has" calls should return false
            TestObject2::class => null,
            TestObject3::class => false,
        ];
        $di = new Di($def);
        $this->assertTrue($di->has(TestInterface::class));
        $this->assertTrue($di->has(TestObject::class));
        $this->assertFalse($di->has(TestObject2::class));
        $this->assertTrue($di->has(TestObject3::class));
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
        // You can also queue closures
        $def[PDO::class . "->"] = [
            function (PDO $pdo) {
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            }
        ];

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
