<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Di\Definitions;
use Kaly\Middleware\Builtin\FileServer;
use Kaly\Middleware\PredefinedResponseHandler;
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
    private App $app;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-fileserver-' . uniqid();
        Fs::mkDir($this->base . '/public');
        Fs::mkDir($this->base . '/modules');
        Fs::putFile($this->base . '/private-note', 'AUDIT-PRIVATE-SENTINEL');
        Fs::putFile($this->base . '/public/asset.txt', 'hello static');
        Fs::putFile($this->base . '/public/sample.php', '<?php /* AUDIT-SOURCE-SENTINEL */');

        $this->app = new App($this->base, false);
        $this->app->addCallback(App::CB_AFTER_DEFINITIONS, function (Definitions $defs): void {
            $defs->bind(ResponseFactoryInterface::class, Psr17Factory::class);
            $defs->bind(StreamFactoryInterface::class, Psr17Factory::class);
        });
        $this->app->boot();
    }

    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
        Fs::rmDir($this->base);
    }

    private function serve(string $method, string $uri): ResponseInterface
    {
        $handler = new PredefinedResponseHandler(new Response(404));
        return (new FileServer())->process(new ServerRequest($method, $uri), $handler);
    }

    public function testServesStaticFile(): void
    {
        $response = $this->serve('GET', '/asset.txt');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello static', (string) $response->getBody());
        $this->assertSame('text/plain', $response->getHeaderLine('Content-Type'));
    }

    public function testHeadHasNoBodyButContentLength(): void
    {
        $response = $this->serve('HEAD', '/asset.txt');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', (string) $response->getBody());
        $this->assertSame('12', $response->getHeaderLine('Content-Length'));
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

    public function testMissingFileIsDelegated(): void
    {
        $response = $this->serve('GET', '/missing.txt');
        $this->assertSame(404, $response->getStatusCode());
    }
}
