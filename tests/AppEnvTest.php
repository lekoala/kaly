<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Tests\Support\TempDir;
use Kaly\Util\Env;
use Kaly\Util\Fs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AppEnvTest extends TestCase
{
    private const KEY = 'KALY_TEST_APP_ENV';
    private const FALLBACK_KEY = 'KALY_TEST_APP_ENV_FALLBACK';

    private string $base;
    /** @var array<mixed> */
    private array $envBackup;
    /** @var array<string, string|false> */
    private array $processBackup = [];

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/kaly-app-env-' . uniqid();
        Fs::ensureDir($this->base);
        $this->envBackup = $_ENV;
        // Explicitly enable file loading for these tests, regardless of PHPUnit's flag.
        foreach ([self::KEY, self::FALLBACK_KEY, App::IGNORE_DOT_ENV] as $key) {
            $this->processBackup[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key]);
        }
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
        foreach ($this->processBackup as $key => $value) {
            putenv($value === false ? $key : $key . '=' . $value);
        }
        TempDir::remove($this->base);
    }

    /** @return array<string, array{?string, ?string, ?string, ?string, string}> */
    public static function loadingCases(): array
    {
        return [
            'process wins' => ['default', 'local', 'process', 'process', 'normal'],
            'local wins' => ['default', 'local', null, 'local', 'normal'],
            'default only' => ['default', null, null, 'default', 'normal'],
            'local only' => [null, 'local', null, 'local', 'normal'],
            'no files' => [null, null, null, null, 'normal'],
            'empty process wins' => ['default', 'local', '', '', 'normal'],
            'empty local wins' => ['default', '', null, '', 'normal'],
            'empty default is defined' => ['', null, null, '', 'normal'],
            'ignore both' => ['default', 'local', null, null, 'ignore-all'],
            'loadEnv false' => ['default', 'local', null, null, 'load-false'],
            'ignore files preserves process' => ['default', 'local', 'process', 'process', 'ignore-all'],
            'existing ENV wins' => ['default', 'local', 'process', 'existing', 'existing'],
        ];
    }

    #[DataProvider('loadingCases')]
    public function testLoadingPrecedence(?string $default, ?string $local, ?string $process, ?string $expected, string $mode): void
    {
        foreach (['.env' => $default, '.env.local' => $local] as $file => $value) {
            if ($value !== null) {
                Fs::putFile($this->base . '/' . $file, self::KEY . '=' . $value . "\n");
            }
        }
        if ($process !== null) {
            putenv(self::KEY . '=' . $process);
        }
        if ($mode === 'existing') {
            $_ENV[self::KEY] = 'existing';
        }
        if ($mode === 'ignore-all') {
            putenv(App::IGNORE_DOT_ENV . '=1');
        }

        new App($this->base, $mode !== 'load-false');

        $this->assertSame($expected !== null, Env::has(self::KEY));
        $this->assertSame($expected ?? '', Env::getString(self::KEY));
        $this->assertSame($process ?? false, getenv(self::KEY));
    }

    public function testDefaultFillsGapsAfterLocalFile(): void
    {
        Fs::putFile($this->base . '/.env.local', self::KEY . "=local\n");
        Fs::putFile($this->base . '/.env', self::KEY . "=default\n" . self::FALLBACK_KEY . "=fallback\n");

        new App($this->base);

        $this->assertSame('local', Env::getString(self::KEY));
        $this->assertSame('fallback', Env::getString(self::FALLBACK_KEY));
    }
}
