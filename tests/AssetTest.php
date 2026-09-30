<?php

declare(strict_types=1);

namespace Kaly\Tests;

use InvalidArgumentException;
use Kaly\Asset\Assets;
use Kaly\Asset\AssetSources;
use Kaly\Util\Fs;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AssetTest extends TestCase
{
    private string $base;
    private AssetSources $sources;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-assets-' . uniqid();
        Fs::ensureDir($this->base . '/public/assets');
        Fs::ensureDir($this->base . '/assets');
        Fs::ensureDir($this->base . '/modules/Admin/assets');
        Fs::putFile($this->base . '/assets/app.js', 'console.log(1);');

        $this->sources = new AssetSources([
            'app' => $this->base . '/assets',
            'admin' => $this->base . '/modules/Admin/assets',
        ]);

        unset($_ENV[Assets::ENV_VERSION]);
    }

    protected function tearDown(): void
    {
        unset($_ENV[Assets::ENV_VERSION]);
        self::removeDir($this->base);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    public function testDevUrls(): void
    {
        $assets = new Assets($this->sources, $this->base . '/public', true);

        $this->assertSame('/_assets/app/app.js', $assets->url('app.js'));
        $this->assertSame('/_assets/app/css/app.css', $assets->url('css/app.css'));
        $this->assertSame('/_assets/admin/admin.js', $assets->url('@admin/admin.js'));
    }

    public function testProdUrlsWithExplicitVersion(): void
    {
        $assets = new Assets($this->sources, $this->base . '/public', false, '8af319c42d');

        $this->assertSame('/assets/8af319c42d/app/app.js', $assets->url('app.js'));
        $this->assertSame('/assets/8af319c42d/admin/admin.js', $assets->url('@admin/admin.js'));
    }

    public function testEnvVersionWinsOverVersionFile(): void
    {
        Fs::putFile($this->base . '/public/assets/.version', "file-version\n");
        $_ENV[Assets::ENV_VERSION] = 'env-version';

        $assets = new Assets($this->sources, $this->base . '/public', false);

        $this->assertSame('/assets/env-version/app/app.js', $assets->url('app.js'));
    }

    public function testVersionFileIsUsedWhenNoExplicitVersion(): void
    {
        Fs::putFile($this->base . '/public/assets/.version', "8af319c42d\n");

        $assets = new Assets($this->sources, $this->base . '/public', false);

        $this->assertSame('/assets/8af319c42d/app/app.js', $assets->url('app.js'));
    }

    public function testMissingVersionThrowsExplicitErrorLazily(): void
    {
        // Construction alone must not fail: an API without assets never calls url()
        $assets = new Assets($this->sources, $this->base . '/public', false);

        try {
            $assets->url('app.js');
            $this->fail('A RuntimeException was expected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('have not been published', $e->getMessage());
        }
    }

    public function testUrlDoesNotCheckFileExistence(): void
    {
        $assets = new Assets($this->sources, $this->base . '/public', true);

        // Like the router: generating a URL never hits the filesystem
        $this->assertSame('/_assets/admin/does-not-exist.js', $assets->url('@admin/does-not-exist.js'));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function invalidAssets(): array
    {
        return [
            'empty' => [''],
            'missing path' => ['@admin'],
            'missing path slash' => ['@admin/'],
            'empty namespace' => ['@/foo.js'],
            'uppercase namespace' => ['@Admin/foo.js'],
            'traversal' => ['../secret.js'],
            'nested traversal' => ['js/../../secret.js'],
            'absolute' => ['/app.js'],
            'dot segment' => ['./app.js'],
            'dotfile' => ['.env'],
            'dotfile nested' => ['js/.hidden.js'],
        ];
    }

    #[DataProvider('invalidAssets')]
    public function testInvalidAssetsAreRejected(string $asset): void
    {
        $assets = new Assets($this->sources, $this->base . '/public', true);

        $this->expectException(InvalidArgumentException::class);
        $assets->url($asset);
    }

    public function testUnknownNamespaceIsRejected(): void
    {
        $assets = new Assets($this->sources, $this->base . '/public', true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown asset namespace '@site'");
        $assets->url('@site/site.js');
    }

    public function testBaseUrlPrefix(): void
    {
        $assets = new Assets($this->sources, $this->base . '/public', true, null, 'https://cdn.example.com');

        $this->assertSame('https://cdn.example.com/_assets/app/app.js', $assets->url('app.js'));
    }
}
