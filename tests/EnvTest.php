<?php

declare(strict_types=1);

namespace Kaly\Tests;

use RuntimeException;
use PHPUnit\Framework\TestCase;
use Kaly\Core\App;
use Kaly\Util\Env;

class EnvTest extends TestCase
{
    protected function setUp(): void
    {
        $_ENV[App::ENV_DEBUG] = true;
    }

    public function testCanSet(): void
    {
        Env::set('test_key', 'test_value');
        $this->assertEquals('test_value', $_ENV['test_key']);
    }

    public function testCanParse(): void
    {
        $result = Env::load(__DIR__ . '/data/env/.env');

        // Let's test some values
        $all = Env::getAll();
        $this->assertArrayHasKey('SOME_EMPTY_VAL', $all);
        $this->assertArrayNotHasKey('INVALID', $all);

        $this->assertEquals('souper_seekret_key', Env::get('SECRET_KEY'));
        $this->assertEquals('souper_seekret_key', Env::getString('SECRET_KEY'));
        $this->assertEquals('default_val', Env::getString('SECRET_KEY_NOT_FOUND', 'default_val'));
        $this->assertEquals("true", Env::get('SOME_TRUE_BOOL'));
        $this->assertTrue(Env::getBool('SOME_TRUE_BOOL'));
        $this->assertEquals("false", Env::get('SOME_FALSE_BOOL'));
        $this->assertFalse(Env::getBool('SOME_FALSE_BOOL'));
        $this->assertNull(Env::get('SOME_NULL_VAL'));
        $this->assertFalse(Env::getBool('SOME_NULL_VAL')); // default value is false
        $this->assertNull(Env::get('SOME_EMPTY_VAL'));
        $this->assertFalse(Env::getBool('SOME_EMPTY_VAL')); // default value is false
        $this->assertIsString(Env::getString('SOME_EMPTY_VAL')); // default value is ''
        $this->assertEquals(1, Env::getInt('SOME_QT'));
        $this->assertEquals(2, Env::getInt('SOME_INVALID_QT', 2));

        // Force redefine
        Env::load(__DIR__ . '/data/env/.env', true);

        // Will throw
        $this->expectException(RuntimeException::class);
        Env::load(__DIR__ . '/data/env/.env');
    }
}
