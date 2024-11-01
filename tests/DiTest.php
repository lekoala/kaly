<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Di\CircularReferenceException;
use Kaly\Di\Container;
use Kaly\Tests\Mocks\TestApp;
use PHPUnit\Framework\TestCase;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TestObject2;
use Kaly\Tests\Mocks\TestObject3;
use Kaly\Tests\Mocks\TestObject4;
use Kaly\Tests\Mocks\TestInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Container\ContainerExceptionInterface;
use Kaly\Di\Definitions;
use Kaly\Tests\Mocks\TestObjectA;
use Kaly\Core\App;

class DiTest extends TestCase
{
    public function testItCanCreateObject(): void
    {
        $di = new Container();
        $inst = $di->get(TestObject::class);
        $this->assertInstanceOf(TestObject::class, $inst);
    }

    public function testItCanCreateInterface(): void
    {
        // It can be bound from definitions
        $di = new Container(
            (new Definitions())
                ->bind(TestObject::class)
        );
        $inst = $di->get(TestInterface::class);
        $this->assertInstanceOf(TestInterface::class, $inst);

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
        $inst = $di->get(TestObjectA::class);
        $this->assertEmpty($inst);
    }

    public function testItBuildsLazily(): void
    {
        TestObject2::$counter = 0;
        $di = new Container(
            (new Definitions())
                ->set(TestObject2::class, fn(): \Kaly\Tests\Mocks\TestObject2 => new TestObject2("lazy"))
        );

        $this->assertEquals(0, TestObject2::$counter);
        $inst = $di->get(TestObject2::class);
        $this->assertEquals(1, TestObject2::$counter);
        $inst2 = $di->get(TestObject2::class);
        $this->assertEquals(1, TestObject2::$counter);
        $this->assertEquals($inst, $inst2);

        TestObject2::$counter = 0;
    }

    public function testItCanRegisterInstance(): void
    {
        $app = new TestApp(__DIR__);
        $di = new Container(
            (new Definitions())
                ->add($app)
        );

        $this->assertEquals($app, $di->get(TestApp::class));
        $this->assertEquals($app, $di->get(App::class));
    }
}
