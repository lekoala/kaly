<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Di\Definitions;
use Kaly\Http\FileResponseFactory;
use Kaly\Http\Middleware\FileServer;
use Kaly\Test\PredefinedResponseHandler;
use Kaly\Tests\Support\TempDir;
use Kaly\Util\Fs;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

class FileServerTest extends TestCase
{
    private string $base;
    private FileServer $server;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-fileserver-' . uniqid();
        Fs::ensureDir($this->base . '/public');
        Fs::ensureDir($this->base . '/modules');
        Fs::putFile($this->base . '/private-note', 'AUDIT-PRIVATE-SENTINEL');
        Fs::putFile($this->base . '/public/asset.txt', 'hello static');
        Fs::putFile($this->base . '/public/sample.php', '<?php /* AUDIT-SOURCE-SENTINEL */');

        $psr17 = new Psr17Factory();
        $this->server = new FileServer($this->base . '/public', new FileResponseFactory($psr17, $psr17));
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->base);
    }

    private function serve(string $method, string $uri): ResponseInterface
    {
        $handler = new PredefinedResponseHandler(new Response(404));
        return $this->server->process(new ServerRequest($method, $uri), $handler);
    }

    public function testServesStaticFile(): void
    {
        $response = $this->serve('GET', '/asset.txt');
        try {
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('hello static', (string) $response->getBody());
            $this->assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        } finally {
            // The body holds an open file handle: release it before tearDown
            // removes the directory (Windows refuses unlink on open files)
            $response->getBody()->close();
        }
    }

    public function testHeadHasNoBodyButContentLength(): void
    {
        $response = $this->serve('HEAD', '/asset.txt');
        try {
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('', (string) $response->getBody());
            $this->assertSame('12', $response->getHeaderLine('Content-Length'));
        } finally {
            $response->getBody()->close();
        }
    }

    public function testPathTraversalIsBlocked(): void
    {
        $response = $this->serve('GET', '/../private-note');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('AUDIT-PRIVATE-SENTINEL', (string) $response->getBody());
    }

    public function testEncodedTraversalIsBlocked(): void
    {
        $response = $this->serve('GET', '/%2e%2e/private-note');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('AUDIT-PRIVATE-SENTINEL', (string) $response->getBody());
    }

    public function testPhpSourceIsNotServed(): void
    {
        $response = $this->serve('GET', '/sample.php');
        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('AUDIT-SOURCE-SENTINEL', (string) $response->getBody());
    }

    public function testWriteMethodIsNotServed(): void
    {
        $response = $this->serve('POST', '/asset.txt');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDotfilesAreNotServed(): void
    {
        Fs::putFile($this->base . '/public/.env', 'DOTFILE-SENTINEL');
        Fs::putFile($this->base . '/public/.git/config', 'GIT-SENTINEL');
        Fs::putFile($this->base . '/public/assets/.version', "v1\n");

        foreach (['/.env', '/.git/config', '/assets/.version'] as $uri) {
            $this->assertSame(404, $this->serve('GET', $uri)->getStatusCode(), $uri);
        }
    }

    public function testWellKnownDirectoryIsServed(): void
    {
        Fs::putFile($this->base . '/public/.well-known/acme-challenge/token', 'challenge');

        $response = $this->serve('GET', '/.well-known/acme-challenge/token');
        try {
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('challenge', (string) $response->getBody());
        } finally {
            $response->getBody()->close();
        }
    }

    public function testWellKnownDoesNotExposeHiddenFiles(): void
    {
        Fs::putFile($this->base . '/public/.env', 'DOTFILE-SENTINEL');
        Fs::putFile($this->base . '/public/.well-known/.env', 'DOTFILE-SENTINEL');
        Fs::putFile($this->base . '/public/.well-known/.git/config', 'GIT-SENTINEL');

        foreach (['/.well-known/.env', '/.well-known/.git/config', '/.well-known/../.env'] as $uri) {
            $response = $this->serve('GET', $uri);
            try {
                $this->assertSame(404, $response->getStatusCode(), $uri);
            } finally {
                $response->getBody()->close();
            }
        }
    }

    public function testMissingFileIsDelegated(): void
    {
        $response = $this->serve('GET', '/missing.txt');
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testResolvesFromContainerWithPublicDirDefault(): void
    {
        $app = new App($this->base, false);
        $app->configure(function (Definitions $defs): void {
            $defs->rebind(ResponseFactoryInterface::class, Psr17Factory::class);
            $defs->rebind(StreamFactoryInterface::class, Psr17Factory::class);
        });
        $app->boot();
        try {
            $server = $app->container()->get(FileServer::class);
            $this->assertInstanceOf(FileServer::class, $server);

            $response = $server->process(new ServerRequest('GET', '/asset.txt'), new PredefinedResponseHandler(new Response(404)));
            try {
                $this->assertSame(200, $response->getStatusCode());
                $this->assertSame('hello static', (string) $response->getBody());
            } finally {
                $response->getBody()->close();
            }
        } finally {
            $app->shutdown();
        }
    }
}
