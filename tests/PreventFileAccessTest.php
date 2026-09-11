<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http\NotFoundException;
use Kaly\Middleware\Builtin\PreventFileAccess;
use Kaly\Middleware\NullHandler;
use Kaly\Middleware\PredefinedResponseHandler;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;

class PreventFileAccessTest extends TestCase
{
    public function testFileRequestIsRejected(): void
    {
        $this->expectException(NotFoundException::class);
        (new PreventFileAccess())->process(new ServerRequest('GET', '/style.css'), new NullHandler());
    }

    public function testDirectoryRequestPassesThrough(): void
    {
        $handler = new PredefinedResponseHandler(new Response(200));
        $response = (new PreventFileAccess())->process(new ServerRequest('GET', '/foo/'), $handler);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDotlessTraversalIsNotCaught(): void
    {
        // PreventFileAccess is a routing guard, not a confinement mechanism:
        // FileServer must still enforce its own public directory boundary.
        $handler = new PredefinedResponseHandler(new Response(200));
        $response = (new PreventFileAccess())->process(new ServerRequest('GET', '/../private-note'), $handler);
        $this->assertSame(200, $response->getStatusCode());
    }
}
