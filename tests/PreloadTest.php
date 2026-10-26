<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\Preload;
use Kaly\Ex;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Support\TempDir;
use Kaly\Util\Fs;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use TestModule\Controller\IndexController;

class PreloadTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/kaly-preload-' . uniqid();
        Fs::ensureDir($dir);
        // Recorded files are real paths
        $this->dir = (string) realpath($dir);
    }

    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
        TempDir::remove($this->dir);
    }

    public function testRecordsTheApplicationAndTheRequestedPaths(): void
    {
        $files = Preload::record($this->app(), ['/test-module/index/foo/'])->files();

        $this->assertContains((string) (new ReflectionClass(App::class))->getFileName(), $files);
        $this->assertContains((string) (new ReflectionClass(IndexController::class))->getFileName(), $files);
        // The entry script records, it is never part of the application
        $this->assertNotContains(get_included_files()[0], $files);
    }

    public function testFailingPathFailsTheRecording(): void
    {
        $this->expectException(Ex::class);
        $this->expectExceptionMessage('Preload path /missing/ answered 404');

        Preload::record($this->app(), ['/missing/']);
    }

    public function testFilesBelowTheScriptAreRelativeToIt(): void
    {
        $preload = Preload::record($this->app());
        $root = (string) realpath(__DIR__ . '/..');

        $inside = $this->normalizeScript($preload->script($root . '/preload.php'));
        $this->assertStringContainsString("    __DIR__ . '/src/Core/App.php',\n", $inside);
        $this->assertStringNotContainsString("'" . str_replace('\\', '/', $root) . '/src/', $inside);

        $outside = $this->normalizeScript($preload->script($this->dir . '/preload.php'));
        $expected = '    ' . var_export(str_replace('\\', '/', $root . '/src/Core/App.php'), true) . ",\n";
        $this->assertStringContainsString($expected, $outside);
    }

    private function normalizeScript(string $script): string
    {
        return str_replace('\\', '/', str_replace('\\\\', '\\', $script));
    }

    public function testPreloadedApplicationBootsAndServesARequest(): void
    {
        $this->requireOpcache();
        $file = $this->dir . '/preload.php';
        Preload::record($this->app())->write($file);

        $serve = $this->dir . '/serve.php';
        Fs::putFile($serve, sprintf(<<<'PHP'
            <?php
            require %s;
            $app = Kaly\Core\App::create(%s)->routing(Kaly\Router\TrailingSlash::Add, true);
            $request = (new Nyholm\Psr7\Factory\Psr17Factory())->createServerRequest('GET', '/test-module/index/foo/');
            $classes = opcache_get_status(false)['preload_statistics']['classes'] ?? [];
            echo $app->handle($request)->getStatusCode(), ' ', json_encode(in_array(Kaly\Core\App::class, $classes, true));
            PHP, var_export(__DIR__ . '/../vendor/autoload.php', true), var_export(__DIR__, true)));

        [$exit, $stdout, $stderr] = $this->php(['-d', 'opcache.preload=' . $file, $serve]);
        $this->assertSame(0, $exit, $stderr);
        $this->assertSame('200 true', $stdout, $stderr);
    }

    public function testBootstrapDependentAndMissingFilesNeverFailTheStartup(): void
    {
        $this->requireOpcache();
        $ns = 'PreloadFixture' . uniqid();
        $fixtures = [
            'functions.php' => "<?php namespace {$ns}; function enabled(): bool { return true; }",
            'Original.php' => "<?php namespace {$ns}; class Original {}",
            // Extends an alias created by the bootstrap, not by its own file
            'Child.php' => "<?php namespace {$ns}; class Child extends Alias {}",
            // Declared only when a bootstrap function says so
            'Conditional.php' => "<?php namespace {$ns}; if (enabled()) { class Conditional {} }",
            'Gone.php' => "<?php namespace {$ns}; class Gone {}",
            'Orphan.php' => "<?php namespace {$ns}; class Orphan extends Gone {}",
        ];
        foreach ($fixtures as $name => $code) {
            Fs::putFile($this->dir . '/' . $name, $code);
        }
        require $this->dir . '/functions.php';
        require $this->dir . '/Original.php';
        class_alias($ns . '\\Original', $ns . '\\Alias');
        foreach (['Child', 'Conditional', 'Gone', 'Orphan'] as $name) {
            require $this->dir . '/' . $name . '.php';
        }

        $file = $this->dir . '/preload.php';
        Preload::record($this->app())->write($file);
        // A parent removed since the recording
        unlink($this->dir . '/Gone.php');

        [$exit, $stdout, $stderr] = $this->php(['-d', 'opcache.preload=' . $file, '-r', 'echo "started";']);
        $this->assertSame(0, $exit, $stderr);
        $this->assertSame('started', $stdout, $stderr);
    }

    private function app(): App
    {
        return App::create(__DIR__)->routing(TrailingSlash::Add, true);
    }

    private function requireOpcache(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('PHP does not support preloading on Windows');
        }
        if ($this->php(['-r', 'echo (int) function_exists("opcache_get_status");'])[1] !== '1') {
            $this->markTestSkipped('Opcache is not available to the PHP binary');
        }
    }

    /**
     * A PHP process with opcache enabled. Startup errors stay on stderr:
     * opcache logs there the classes it cannot preload, which is expected.
     *
     * @param list<string> $args
     * @return array{int, string, string} exit code, stdout, stderr
     */
    private function php(array $args): array
    {
        $process = proc_open(
            [PHP_BINARY, '-d', 'opcache.enable_cli=1', '-d', 'display_startup_errors=0', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $stdout, $stderr];
    }
}
