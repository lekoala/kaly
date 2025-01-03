<?php

declare(strict_types=1);

namespace Kaly\Tests;

use AssertionError;
use Kaly\Di\CircularReferenceException;
use Kaly\Di\Container;
use Kaly\Tests\Mocks\TestApp;
use PHPUnit\Framework\TestCase;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObject2;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Di\Definitions;
use Kaly\Tests\Mocks\TestObjectA;
use Kaly\Core\App;
use Kaly\Di\Injector;
use Kaly\Tests\Mocks\TestObject5;
use Kaly\Tests\Mocks\TestAltInterface;
use Kaly\Tests\Mocks\TestObject6;
use Kaly\Util\Refl;

class DiTest extends TestCase
{
    public function testDefinitions(): void
    {
        $arr = [
            TestInterface::class => TestObject::class
        ];

        $def = new Definitions($arr);
        $def2 = Definitions::create($arr);

        $this->assertEquals($def2, $def);
        $this->assertTrue($def2 !== $def);

        $this->assertTrue($def->has(TestInterface::class));
        $this->assertFalse($def->miss(TestInterface::class));
        $this->assertEquals(TestObject::class, $def->get(TestInterface::class));

        $obj = new TestObject5('v', 'v2', []);
        $def->add($obj);
        $this->assertTrue($def->has(TestAltInterface::class));
        $this->assertTrue($def->has(TestObject5::class));

        $def->lock();
        $this->assertTrue($def->isLocked());

        // Throws assert errors afterwards
        $this->expectException(AssertionError::class);
        $def->set("something", "something");
    }

    public function testMergeDefinitions(): void
    {
        $def1 = Definitions::create()->set('obj', TestObject::class);
        $def2 = Definitions::create()->set('obj2', TestObject2::class);

        $this->assertTrue($def1->has('obj'));
        $this->assertTrue($def2->has('obj2'));

        $def1->parameter(TestObject5::class, 'v', 'provided_value');
        $def1->callback(TestObject::class, fn($obj) => $obj);
        $def1->resolve(TestObject::class, 'v', fn($k) => $k);

        // parameters can come from multiple source, the latest to be merged will overwrite any existing param
        $def2->parameter(TestObject5::class, 'v2', 'provided value');

        $final = new Definitions($def1);
        $final->merge($def2);

        $this->assertTrue($final->has('obj'));
        $this->assertTrue($final->has('obj2'));
        $this->assertArrayHasKey(TestObject5::class, $final->getParameters());
        $this->assertArrayHasKey('v', $final->getParameters()[TestObject5::class]);
        $this->assertArrayHasKey('v2', $final->getParameters()[TestObject5::class]);
        $this->assertArrayHasKey(TestObject::class, $final->getCallbacks());
        $this->assertArrayHasKey(TestObject::class, $final->getResolvers());
    }

    public function testInjectorCreate(): void
    {
        $injector = new Injector();
        $inst = $injector->make(TestObject5::class, v: 'test', v2: 'test');

        $this->assertInstanceOf(TestObject5::class, $inst);
        $this->assertEquals('test', $inst->v);
        $this->assertEquals('test', $inst->v2);
        $this->assertEquals([], $inst->arr); // it was provided automatically
        $this->assertEquals(null, $inst->v3); // its nullable

        // You can also use ...array if you don't like named arguments
        $inst = $injector->make(TestObject5::class, ...['v' => 'test', 'v2' => 'test']);
        $this->assertEquals($inst, $inst);

        $definitions = Definitions::create()
            ->parameter(TestObject5::class, 'v', 'from definitions')
            ->lock();

        $container = new Container($definitions);
        $injectorContainer = new Injector($container);

        // make() creates a fresh class without taking the definitions into account
        // This time, we skip 'v'
        $inst = $injector->make(TestObject5::class, v2: 'test');
        $this->assertEquals('', $inst->v);
        $this->assertEquals('test', $inst->v2);

        // but with the container, the definitions are used
        $parameters = $definitions->allParametersFor(TestObject5::class);
        $this->assertEquals('from definitions', $parameters['v']);

        $instFromContainer = $container->get(TestObject5::class);
        $this->assertNotEquals($inst, $instFromContainer);
        $this->assertEquals('from definitions', $instFromContainer->v);
        // Injector will return empty strings if nulls are not accepted
        $this->assertEquals('', $instFromContainer->v2);

        // if object has been created by container, the injector will use it
        $fn = fn(TestObject5 $a) => $a;
        $this->assertEquals($instFromContainer, $injectorContainer->invoke($fn));

        // With an injector without container, this would not work because it cannot build a TestObject5
        // $this->assertEquals($instFromContainer, $injector->invoke($fn));
    }

    public function testInjectorTypes(): void
    {
        $injector = new Injector();
        $fn = fn() => 'test';
        $this->assertEquals('test', $injector->invoke($fn));
        // no value = empty string
        $fn = fn(string $a) => $a;
        $this->assertEquals('', $injector->invoke($fn));
        // provide a value (named)
        $fn = fn(string $a, string $b) => $a . $b;
        $this->assertEquals('testother', $injector->invoke($fn, b: 'other', a: 'test'));
        // default value is preferred
        $fn = fn(string $a, string $b = 'other') => $a . $b;
        $this->assertEquals('testother', $injector->invoke($fn, a: 'test'));
        // provide a value (positional). Null values must work
        $fn = fn(string $a, string $b) => $a . $b;
        $this->assertEquals('testother', $injector->invoke($fn, 'test', 'other'));
        $fn = fn(string $a, ?string $b, ?string $c) => $a . $b . $c;
        $this->assertEquals('testother', $injector->invoke($fn, 'test', null, 'other'));
        // you can use invokeArray syntax (named, positional)
        $fn = fn(string $a) => $a;
        $this->assertEquals('test', $injector->invokeArray($fn, [
            'a' => 'test'
        ]));
        $this->assertEquals('test', $injector->invokeArray($fn, [
            'test'
        ]));
        // complex types
        $fn = fn(string|bool $a) => $a;
        $this->assertEquals(true, $injector->invoke($fn, true));
        $this->assertEquals('test', $injector->invoke($fn, 'test'));
        // intersection type
        $fn = fn(TestInterface&TestAltInterface $intersection) => $intersection;
        $demo = new TestObject6('test', 'test', []);
        $this->assertEquals($demo, $injector->invoke($fn, $demo));
        // union type
        $fn = fn(TestInterface|TestAltInterface $intersection) => $intersection;
        $demo = new TestObject5('test', 'test', []);
        $this->assertEquals($demo, $injector->invoke($fn, $demo));
        $demo = new TestObject6('test', 'test', []);
        $this->assertEquals($demo, $injector->invoke($fn, $demo));
        // provide an invalid value throws AssertionError
        $fn = fn(string $a) => $a;
        $this->expectException(AssertionError::class);
        $this->assertEquals('test', $injector->invoke($fn, a: true));
    }

    public function testItCanCreateObject(): void
    {
        $di = new Container();
        $inst = $di->get(TestObject::class);
        $this->assertInstanceOf(TestObject::class, $inst);
    }

    public function testItCanCreateInterface(): void
    {
        $di = new Container(
            Definitions::create()
                ->bind(TestObject::class) // autobinds since there is only one interface
        );

        // You can get by interface
        $inst = $di->get(TestInterface::class);
        $this->assertInstanceOf(TestInterface::class, $inst);

        // Or by class
        $inst2 = $di->get(TestObject::class);
        $this->assertEquals($inst, $inst2); // it's the same object

        // It can use a simple array mapping
        $di = new Container([
            TestInterface::class => TestObject::class
        ]);
        $inst = $di->get(TestInterface::class);
        $this->assertInstanceOf(TestInterface::class, $inst);
    }

    public function testItCanDetectCircularDependancies(): void
    {
        $di = new Container();
        $this->expectException(CircularReferenceException::class);

        // A depends on B which depends on A
        $inst = $di->get(TestObjectA::class);
        $this->assertEmpty($inst);
    }

    public function testItBuildsLazily(): void
    {
        // Counter is incremented when constructing the object
        TestObject2::$counter = 0;
        $di = new Container(
            Definitions::create()
                ->set(TestObject2::class, fn(): \Kaly\Tests\Mocks\TestObject2 => new TestObject2("lazy"))
        );

        // Definitions are set, but lazy factory is not yet called
        $this->assertEquals(0, TestObject2::$counter);

        // Object is constructed, counter = 1
        $inst = $di->get(TestObject2::class);
        $this->assertEquals(1, TestObject2::$counter);

        // Object is retrieved from cache, counter = 1
        $inst2 = $di->get(TestObject2::class);
        $this->assertEquals(1, TestObject2::$counter);
        $this->assertEquals($inst, $inst2);

        TestObject2::$counter = 0;
    }

    public function testItCanRegisterInstance(): void
    {
        $app = new TestApp(__DIR__);
        $di = new Container(
            Definitions::create()
                ->add($app)
        );

        // Get by exact class name
        $this->assertEquals($app, $di->get(TestApp::class));

        // Get by parent class
        $this->assertEquals($app, $di->get(App::class));
    }
}
