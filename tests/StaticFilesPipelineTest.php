<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Di\Definitions;
use Kaly\Http\Middleware\FileServer;
use Kaly\Http\Middleware\PreventFileAccess;
use Kaly\Tests\Support\TempDir;
use Kaly\Util\Fs;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The FileServer + PreventFileAccess pair as the demo and the docs recommend.
 *
 * Both middlewares are correct in isolation, but they interact through the
 * ascending-priority order of the incoming band: the static server must run
 * before the routing guard that rejects any dotted path.
 */
class StaticFilesPipelineTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-static-pipeline-' . uniqid();
        Fs::ensureDir($this->base . '/public');
        Fs::ensureDir($this->base . '/modules');
        Fs::putFile($this->base . '/public/app.css', 'body{margin:0}');
        Fs::putFile($this->base . '/public/app.js', 'console.log(1)');
        Fs::putFile($this->base . '/public/sample.php', '<?php /* AUDIT-SOURCE-SENTINEL */');
    }

    protected function tearDown(): void
    {
        TempDir::remove($this->base);
    }

    private function app(): App
    {
        $app = new App($this->base, false);
        $app->configure(function (Definitions $defs): void {
            $defs->rebind(ResponseFactoryInterface::class, Psr17Factory::class);
            $defs->rebind(StreamFactoryInterface::class, Psr17Factory::class);
        });
        $app->middleware()->incoming(FileServer::class, priority: -100)->incoming(PreventFileAccess::class);
        return $app->boot();
    }

    private function get(App $app, string $uri): ResponseInterface
    {
        return $app->handle(new ServerRequest('GET', $uri));
    }

    public function testCssAndJsAreServedBeforeTheRoutingGuard(): void
    {
        $app = $this->app();
        try {
            $css = $this->get($app, '/app.css');
            try {
                $this->assertSame(200, $css->getStatusCode());
                $this->assertSame('text/css', $css->getHeaderLine('Content-Type'));
                $this->assertSame('body{margin:0}', (string) $css->getBody());
            } finally {
                $css->getBody()->close();
            }

            $js = $this->get($app, '/app.js');
            try {
                $this->assertSame(200, $js->getStatusCode());
                $this->assertSame('application/javascript', $js->getHeaderLine('Content-Type'));
            } finally {
                $js->getBody()->close();
            }
        } finally {
            $app->shutdown();
        }
    }

    public function testForbiddenAndMissingFilesStillFail(): void
    {
        $app = $this->app();
        try {
            $php = $this->get($app, '/sample.php');
            try {
                $this->assertSame(404, $php->getStatusCode());
                $this->assertStringNotContainsString('AUDIT-SOURCE-SENTINEL', (string) $php->getBody());
            } finally {
                $php->getBody()->close();
            }

            $missing = $this->get($app, '/missing.css');
            try {
                $this->assertSame(404, $missing->getStatusCode());
            } finally {
                $missing->getBody()->close();
            }
        } finally {
            $app->shutdown();
        }
    }
}
