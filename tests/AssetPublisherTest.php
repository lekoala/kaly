<?php

declare(strict_types=1);

namespace Kaly\Tests;

use InvalidArgumentException;
use Kaly\Asset\AssetPublisher;
use Kaly\Asset\Assets;
use Kaly\Asset\AssetSources;
use Kaly\Util\Fs;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AssetPublisherTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-publisher-' . uniqid();
        Fs::ensureDir($this->base . '/public');
        Fs::ensureDir($this->base . '/modules');

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

    private function sources(): AssetSources
    {
        return new AssetSources([
            'app' => $this->base . '/assets',
            'admin' => $this->base . '/modules/Admin/assets',
        ]);
    }

    private function seedHappyPath(): void
    {
        Fs::putFile($this->base . '/assets/app.js', "import './components/dialog.js';\n");
        Fs::putFile($this->base . '/assets/components/dialog.js', 'export default 1;');
        Fs::putFile($this->base . '/assets/images/logo.svg', '<svg></svg>');
        Fs::putFile($this->base . '/assets/.env', 'secret');
        Fs::ensureDir($this->base . '/assets/.git');
        Fs::putFile($this->base . '/assets/.git/HEAD', 'ref');
        Fs::putFile($this->base . '/modules/Admin/assets/admin.js', 'console.log(1);');
        Fs::putFile($this->base . '/modules/Admin/assets/admin.css', 'body {}');
    }

    public function testPublishWithExplicitVersion(): void
    {
        $this->seedHappyPath();
        $publisher = new AssetPublisher($this->sources(), $this->base . '/public');

        $version = $publisher->publish('v1');

        $this->assertSame('v1', $version);
        $this->assertSame("import './components/dialog.js';\n", Fs::getFile($this->base . '/public/assets/v1/app/app.js'));
        $this->assertSame('export default 1;', Fs::getFile($this->base . '/public/assets/v1/app/components/dialog.js'));
        $this->assertSame('<svg></svg>', Fs::getFile($this->base . '/public/assets/v1/app/images/logo.svg'));
        $this->assertSame('console.log(1);', Fs::getFile($this->base . '/public/assets/v1/admin/admin.js'));
        // Dot segments are never published
        $this->assertFileDoesNotExist($this->base . '/public/assets/v1/app/.env');
        $this->assertFileDoesNotExist($this->base . '/public/assets/v1/app/.git/HEAD');
        // The version file links the build to the runtime
        $this->assertSame("v1\n", Fs::getFile($this->base . '/public/assets/.version'));
    }

    public function testPublishFallsBackToContentHash(): void
    {
        $this->seedHappyPath();
        $publisher = new AssetPublisher($this->sources(), $this->base . '/public');

        $version = $publisher->publish();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{10}$/D', $version);
        $this->assertFileExists($this->base . '/public/assets/' . $version . '/app/app.js');
        $this->assertSame($version . "\n", Fs::getFile($this->base . '/public/assets/.version'));
    }

    public function testContentHashIsDeterministicAndContentBased(): void
    {
        $this->seedHappyPath();
        $publisher = new AssetPublisher($this->sources(), $this->base . '/public');

        $first = $publisher->contentHash();
        $second = $publisher->contentHash();
        $this->assertSame($first, $second);

        Fs::putFile($this->base . '/assets/app.js', "import './components/dialog.js';\n// changed\n");
        $this->assertNotSame($first, $publisher->contentHash());
    }

    public function testExistingDestinationIsLeftUntouched(): void
    {
        $this->seedHappyPath();
        $publisher = new AssetPublisher($this->sources(), $this->base . '/public');
        $publisher->publish('v1');

        // A build error: contents changed without bumping the explicit version
        Fs::putFile($this->base . '/assets/app.js', 'changed');
        $publisher->publish('v1');

        $this->assertSame("import './components/dialog.js';\n", Fs::getFile($this->base . '/public/assets/v1/app/app.js'));
    }

    public function testMissingAppDirPublishesModulesOnly(): void
    {
        Fs::putFile($this->base . '/modules/Admin/assets/admin.js', 'console.log(1);');
        $publisher = new AssetPublisher($this->sources(), $this->base . '/public');

        $publisher->publish('v1');

        $this->assertFileExists($this->base . '/public/assets/v1/admin/admin.js');
        $this->assertFileDoesNotExist($this->base . '/public/assets/v1/app/app.js');
    }

    public function testPhpExtensionIsRefused(): void
    {
        $this->seedHappyPath();
        Fs::putFile($this->base . '/assets/evil.php', '<?php echo 1;');
        $publisher = new AssetPublisher($this->sources(), $this->base . '/public');

        try {
            $publisher->publish('v1');
            $this->fail('A RuntimeException was expected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('.php', $e->getMessage());
        }
        $this->assertFileDoesNotExist($this->base . '/public/assets/v1/app/evil.php');
    }

    public function testSymlinkFailsLoudly(): void
    {
        $this->seedHappyPath();
        $link = $this->base . '/assets/link.js';
        if (!@symlink($this->base . '/assets/app.js', $link)) {
            $this->markTestSkipped('Symlinks are not available');
        }

        $publisher = new AssetPublisher($this->sources(), $this->base . '/public');

        try {
            $publisher->publish('v1');
            $this->fail('A RuntimeException was expected');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ymlink', $e->getMessage());
        }
    }

    public function testInvalidVersionIsRejected(): void
    {
        $this->seedHappyPath();
        $publisher = new AssetPublisher($this->sources(), $this->base . '/public');

        $this->expectException(InvalidArgumentException::class);
        $publisher->publish('../evil');
    }

    public function testPruneRemovesTheOldestVersions(): void
    {
        $this->seedHappyPath();
        $publisher = new AssetPublisher($this->sources(), $this->base . '/public');

        foreach (['v1', 'v2', 'v3', 'v4'] as $index => $version) {
            $publisher->publish($version);
            // v4 is live; v1 is the oldest on disk
            touch($this->base . "/public/assets/{$version}", time() - 400 + (100 * $index));
        }

        $this->assertSame(2, $publisher->prune(keep: 2));
        $this->assertFileDoesNotExist($this->base . '/public/assets/v1');
        $this->assertFileDoesNotExist($this->base . '/public/assets/v2');
        $this->assertDirectoryExists($this->base . '/public/assets/v3');
        $this->assertDirectoryExists($this->base . '/public/assets/v4');
    }

    public function testPruneNeverRemovesTheLiveVersion(): void
    {
        $this->seedHappyPath();
        $publisher = new AssetPublisher($this->sources(), $this->base . '/public');
        $publisher->publish('v1');
        $publisher->publish('v2');
        $publisher->publish('v3');

        // Rolled back: v1 is live but the oldest on disk
        touch($this->base . '/public/assets/v1', time() - 300);
        touch($this->base . '/public/assets/v2', time() - 200);
        touch($this->base . '/public/assets/v3', time() - 100);
        Fs::putFile($this->base . '/public/assets/.version', "v1\n");

        $this->assertSame(1, $publisher->prune(keep: 1));
        $this->assertDirectoryExists($this->base . '/public/assets/v1');
        $this->assertFileDoesNotExist($this->base . '/public/assets/v2');
        $this->assertDirectoryExists($this->base . '/public/assets/v3');
    }

    public function testPruneWithoutPublishedAssetsIsNoop(): void
    {
        $publisher = new AssetPublisher($this->sources(), $this->base . '/public');
        $this->assertSame(0, $publisher->prune());
    }

    public function testPublishedVersionFeedsAssetUrls(): void
    {
        $this->seedHappyPath();
        $publisher = new AssetPublisher($this->sources(), $this->base . '/public');
        $version = $publisher->publish();

        $assets = new Assets($this->sources(), $this->base . '/public', false);

        $this->assertSame("/assets/{$version}/app/app.js", $assets->url('app.js'));
    }
}
