<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\Middleware\NullHandler;
use Kaly\Http\Exception\NotFoundException;
use Kaly\Http\Middleware\PreventSensitivePathAccess;
use Kaly\Test\PredefinedResponseHandler;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class PreventSensitivePathAccessTest extends TestCase
{
    private function passes(string $uri): void
    {
        $handler = new PredefinedResponseHandler(new Response(200));
        $response = (new PreventSensitivePathAccess())->process(new ServerRequest('GET', $uri), $handler);
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * @param list<string> $uris
     */
    private function blocked(array $uris): void
    {
        $rejected = 0;
        foreach ($uris as $uri) {
            try {
                (new PreventSensitivePathAccess())->process(new ServerRequest('GET', $uri), new NullHandler());
            } catch (NotFoundException) {
                $rejected++;
                continue;
            }
            $this->fail("{$uri} must be blocked");
        }
        $this->assertSame(count($uris), $rejected);
    }

    public function testDottedApplicationRoutesPass(): void
    {
        $this->passes('/sitemap.xml');
        $this->passes('/robots.txt');
        $this->passes('/feed.atom');
        $this->passes('/archive.tar.gz');
        $this->passes('/foo/');
    }

    public function testWellKnownPassesButNestedHiddenIsBlocked(): void
    {
        $this->passes('/.well-known/acme-challenge/token');
        $this->blocked(['/.well-known/.env']);
    }

    public function testSensitivePathsAreBlocked(): void
    {
        $this->blocked([
            '/.env',
            '/foo/.env',
            '/.env.local',
            '/.git/config',
            '/composer.json',
            '/composer.lock',
            '/evil.php',
            '/foo/bar.PHP',
        ]);
    }

    public function testTraversalAndAmbiguousEncodingsAreBlocked(): void
    {
        $this->blocked(['/a/../.env', '/%2eenv', '/%252eenv', '/%2Fetc', '/foo\\bar', '/%00']);
    }
}
