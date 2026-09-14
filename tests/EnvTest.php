<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Util\Env;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class EnvTest extends TestCase
{
    /** @var array<string,mixed> */
    private array $envBackup = [];

    /** @var array<string> */
    private array $putenvKeys = [];

    protected function setUp(): void
    {
        $this->envBackup = $_ENV;
        $this->putenvKeys = [];
        $_ENV[App::ENV_DEBUG] = true;
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
        foreach ($this->putenvKeys as $key) {
            putenv($key);
        }
        $this->putenvKeys = [];
    }

    private function setProcessEnv(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        unset($_ENV[$key]);
        $this->putenvKeys[] = $key;
    }

    private function unsetProcessEnv(string $key): void
    {
        putenv($key);
        unset($_ENV[$key]);
        $this->putenvKeys[] = $key;
    }

    public function testCanSet(): void
    {
        Env::set('test_key', 'test_value');
        $this->assertEquals('test_value', $_ENV['test_key']);
    }

    public function testCanParse(): void
    {
        foreach (['S3_BUCKET', 'SECRET_KEY', 'SOME_TRUE_BOOL', 'SOME_FALSE_BOOL', 'SOME_NULL_VAL', 'SOME_EMPTY_VAL', 'SOME_QT', 'SOME_FLOAT', 'SOME_ARRAY'] as $key) {
            $this->unsetProcessEnv($key);
        }

        $result = Env::load(__DIR__ . '/data/env/.env');
        $this->assertArrayHasKey('SECRET_KEY', $result);

        // Let's test some values
        $all = Env::getAll();
        $this->assertArrayHasKey('SOME_EMPTY_VAL', $all);
        $this->assertArrayNotHasKey('INVALID', $all);

        $this->assertEquals('souper_seekret_key', Env::get('SECRET_KEY'));
        $this->assertEquals('souper_seekret_key', Env::getString('SECRET_KEY'));
        $this->assertEquals('default_val', Env::getString('SECRET_KEY_NOT_FOUND', 'default_val'));
        $this->assertEquals('true', Env::get('SOME_TRUE_BOOL'));
        $this->assertTrue(Env::getBool('SOME_TRUE_BOOL'));
        $this->assertEquals('false', Env::get('SOME_FALSE_BOOL'));
        $this->assertFalse(Env::getBool('SOME_FALSE_BOOL'));
        $this->assertNull(Env::get('SOME_NULL_VAL'));
        $this->assertFalse(Env::getBool('SOME_NULL_VAL')); // default value is false
        $this->assertNull(Env::get('SOME_EMPTY_VAL'));
        $this->assertFalse(Env::getBool('SOME_EMPTY_VAL')); // default value is false
        $this->assertIsString(Env::getString('SOME_EMPTY_VAL')); // default value is ''
        $this->assertEquals(1, Env::getInt('SOME_QT'));
        $this->assertEquals(2, Env::getInt('SOME_INVALID_QT', 2));

        // Without overwrite, already defined keys are skipped, not overwritten
        $second = Env::load(__DIR__ . '/data/env/.env');
        $this->assertSame([], $second);
        $this->assertEquals('souper_seekret_key', Env::get('SECRET_KEY'));

        // Force redefine
        $forced = Env::load(__DIR__ . '/data/env/.env', true);
        $this->assertArrayHasKey('SECRET_KEY', $forced);
    }

    public function testRealEnvironmentWinsOverDotEnv(): void
    {
        $this->setProcessEnv('KALY_TEST_REAL', 'from_process');

        $file = tempnam(sys_get_temp_dir(), 'kaly-env');
        $this->assertIsString($file);
        file_put_contents($file, "KALY_TEST_REAL=\"from_dotenv\"\n");
        try {
            // Skipped: the process value stays visible through Env
            $this->assertSame([], Env::load($file));
            $this->assertSame('from_process', Env::get('KALY_TEST_REAL'));

            // Overwrite only affects the Env view, not the process itself
            $forced = Env::load($file, true);
            $this->assertSame(['KALY_TEST_REAL' => 'from_dotenv'], $forced);
            $this->assertSame('from_dotenv', Env::get('KALY_TEST_REAL'));
            $this->assertSame('from_process', getenv('KALY_TEST_REAL'));
        } finally {
            unlink($file);
        }
    }

    public function testReadsProcessEnvWithoutServerEntry(): void
    {
        $this->setProcessEnv('KALY_TEST_FALLBACK', 'hello');

        $this->assertTrue(Env::has('KALY_TEST_FALLBACK'));
        $this->assertSame('hello', Env::get('KALY_TEST_FALLBACK'));
        $this->assertArrayHasKey('KALY_TEST_FALLBACK', Env::getAll());
    }

    public function testExplicitNullStaysDefined(): void
    {
        $this->unsetProcessEnv('KALY_NULL_KEY');
        $_ENV['KALY_NULL_KEY'] = null;

        // Explicit null counts as defined: no getenv() fallback, has() is true
        $this->assertTrue(Env::has('KALY_NULL_KEY'));
        $this->assertNull(Env::get('KALY_NULL_KEY'));

        $file = tempnam(sys_get_temp_dir(), 'kaly-env');
        $this->assertIsString($file);
        file_put_contents($file, "KALY_NULL_KEY=\"from_dotenv\"\n");
        try {
            $this->assertSame([], Env::load($file));
            $this->assertNull(Env::get('KALY_NULL_KEY'));
        } finally {
            unlink($file);
        }
    }

    public function testGetAllPrefersEnvView(): void
    {
        $this->setProcessEnv('KALY_MERGE_KEY', 'process');
        $_ENV['KALY_MERGE_KEY'] = 'view';

        $all = Env::getAll();
        $this->assertSame('view', $all['KALY_MERGE_KEY']);
    }

    public function testRejectsInvalidKeyName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid environment variable name');
        Env::load(__DIR__ . '/data/env/invalid-key.env');
    }

    public function testRejectsArrayValue(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a string');
        Env::load(__DIR__ . '/data/env/array-value.env');
    }

    public function testEmptyFileIsAccepted(): void
    {
        $result = Env::load(__DIR__ . '/data/env/empty.env');
        $this->assertSame([], $result);
    }

    public function testValuesAreRawStrings(): void
    {
        foreach (['RAW_BOOL', 'RAW_NUM', 'RAW_QUOTED'] as $key) {
            $this->unsetProcessEnv($key);
        }
        Env::load(__DIR__ . '/data/env/raw.env', true);

        // Unquoted values must stay strings, the caller decides how to type them
        $this->assertSame('true', Env::get('RAW_BOOL'));
        $this->assertSame('42', Env::get('RAW_NUM'));
        $this->assertSame('yes', Env::get('RAW_QUOTED'));
    }
}
