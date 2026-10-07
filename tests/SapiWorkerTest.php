<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Router\TrailingSlash;
use PHPUnit\Framework\TestCase;

/**
 * A FrankenPHP worker, simulated: the app boots once, then the runtime
 * refreshes the superglobals and calls run() for every request.
 *
 * ```php
 * $app = App::create(dirname(__DIR__))->boot();
 * while (frankenphp_handle_request(static fn() => $app->run())) {
 *     gc_collect_cycles();
 * }
 * ```
 *
 * Nothing but the superglobals changes between two cycles: whatever the app
 * reads must come from the request of the cycle.
 */
class SapiWorkerTest extends TestCase
{
    /**
     * @var array<mixed>
     */
    private array $server;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = [];
        $_COOKIE = [];
        ErrorHandler::restoreDefaults();
    }

    /**
     * What the runtime does around the worker callback: fresh superglobals
     * in, the output of this request only out
     *
     * @param array<string,string> $query
     * @param array<string,string> $cookies
     */
    private function cycle(App $app, string $uri, array $query = [], array $cookies = []): string
    {
        $_SERVER = [
            ...$this->server,
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri . ($query !== [] ? '?' . http_build_query($query) : ''),
            'QUERY_STRING' => http_build_query($query),
            'HTTP_HOST' => 'localhost',
        ];
        $_GET = $query;
        $_COOKIE = $cookies;

        ob_start();
        $app->run();
        return (string) ob_get_clean();
    }

    public function testRunServesManyRequestsFromTheGlobalsOfEachCycle(): void
    {
        $app = App::create(__DIR__)->routing(TrailingSlash::Add, true)->boot();

        $this->assertSame('q=one;theme=dark;', $this->cycle($app, '/test-module/state/echo/', ['q' => 'one'], ['theme' => 'dark']));
        // The previous query and cookie must not survive
        $this->assertSame('q=-;theme=-;', $this->cycle($app, '/test-module/state/echo/'));
        $this->assertSame('hello', $this->cycle($app, '/test-module/'));
        $this->assertSame('q=two;theme=-;', $this->cycle($app, '/test-module/state/echo/', ['q' => 'two']));
    }

    public function testAFailingCycleDoesNotStopTheWorker(): void
    {
        $app = App::create(__DIR__)->debug(false)->routing(TrailingSlash::Add, true)->boot();

        $this->assertSame('Server error', $this->cycle($app, '/test-module/index/middlewareexception/'));
        $this->assertSame('hello', $this->cycle($app, '/test-module/'));
    }
}
