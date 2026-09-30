<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Asset\AssetsInterface;
use Kaly\Asset\AssetSources;
use Kaly\Core\App;
use Kaly\Di\Definitions;
use Kaly\Http\FileResponseFactory;
use Kaly\Middleware\AssetServer;
use Kaly\Test\PredefinedResponseHandler;
use Kaly\Util\Fs;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

class AssetServerTest extends TestCase
{
    private string $base;
    private AssetServer $server;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-assetserver-' . uniqid();
        Fs::ensureDir($this->base . '/public');
        Fs::ensureDir($this->base . '/modules');
        Fs::putFile($this->base . '/assets/app.js', 'console.log(1);');
        Fs::putFile($this->base . '/assets/.secret', 'nope');
        Fs::putFile($this->base . '/assets/evil.php', '<?php /* AUDIT-SOURCE-SENTINEL */');
        Fs::putFile($this->base . '/private-note', 'AUDIT-PRIVATE-SENTINEL');
        Fs::putFile($this->base . '/modules/Admin/assets/admin.css', 'body {}');

        $psr17 = new Psr17Factory();
        $sources = new AssetSources([
            'app' => $this->base . '/assets',
            'admin' => $this->base . '/modules/Admin/assets',
        ]);
        $this->server = new AssetServer($sources, new FileResponseFactory($psr17, $psr17));
    }

    protected function tearDown(): void
    {
        self::removeDir($this->base);
    }

    private static function removeDir(string $dir, int $attempts = 5): void
    {
        for ($try = 1;; $try++) {
            try {
                self::removeDirOnce($dir);
                return;
            } catch (\Throwable $e) {
                if ($try >= $attempts) {
                    throw $e;
                }
                clearstatcache();
                usleep(10_000 * $try);
            }
        }
    }

    private static function removeDirOnce(string $dir): void
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

    private function serve(string $method, string $uri): ResponseInterface
    {
        $handler = new PredefinedResponseHandler(new Response(404));
        return $this->server->process(new ServerRequest($method, $uri), $handler);
    }

    public function testServesNamespacedFileWithNoStore(): void
    {
        $response = $this->serve('GET', '/_assets/app/app.js');
        try {
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('console.log(1);', (string) $response->getBody());
            $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        } finally {
            $response->getBody()->close();
        }
    }

    public function testServesModuleNamespace(): void
    {
        $response = $this->serve('GET', '/_assets/admin/admin.css');
        try {
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('body {}', (string) $response->getBody());
        } finally {
            $response->getBody()->close();
        }
    }

    public function testHeadHasNoBody(): void
    {
        $response = $this->serve('HEAD', '/_assets/app/app.js');
        try {
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('', (string) $response->getBody());
        } finally {
            $response->getBody()->close();
        }
    }

    public function testUnknownNamespaceIsDelegated(): void
    {
        $this->assertSame(404, $this->serve('GET', '/_assets/site/site.js')->getStatusCode());
    }

    public function testPathTraversalIsBlocked(): void
    {
        $response = $this->serve('GET', '/_assets/app/../private-note');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('AUDIT-PRIVATE-SENTINEL', (string) $response->getBody());
    }

    public function testEncodedTraversalIsBlocked(): void
    {
        $response = $this->serve('GET', '/_assets/app/%2e%2e/private-note');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('AUDIT-PRIVATE-SENTINEL', (string) $response->getBody());
    }

    public function testDotfilesAreNotServed(): void
    {
        $this->assertSame(404, $this->serve('GET', '/_assets/app/.secret')->getStatusCode());
    }

    public function testPhpSourceIsNotServed(): void
    {
        $response = $this->serve('GET', '/_assets/app/evil.php');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('AUDIT-SOURCE-SENTINEL', (string) $response->getBody());
    }

    public function testWriteMethodIsNotServed(): void
    {
        $this->assertSame(404, $this->serve('POST', '/_assets/app/app.js')->getStatusCode());
    }

    public function testOtherPrefixesAreIgnored(): void
    {
        $this->assertSame(404, $this->serve('GET', '/assets/app/app.js')->getStatusCode());
        $this->assertSame(404, $this->serve('GET', '/_assets/')->getStatusCode());
    }

    public function testMissingFileIsDelegated(): void
    {
        $this->assertSame(404, $this->serve('GET', '/_assets/app/missing.js')->getStatusCode());
    }

    public function testResolvesFromContainer(): void
    {
        $app = new App($this->base, false);
        $app->configure(function (Definitions $defs): void {
            $defs->bind(ResponseFactoryInterface::class, Psr17Factory::class);
            $defs->bind(StreamFactoryInterface::class, Psr17Factory::class);
        });
        $app->boot();
        try {
            $this->assertInstanceOf(AssetServer::class, $app->container()->get(AssetServer::class));
            $this->assertInstanceOf(AssetsInterface::class, $app->container()->get(AssetsInterface::class));
        } finally {
            $app->shutdown();
        }
    }
}
