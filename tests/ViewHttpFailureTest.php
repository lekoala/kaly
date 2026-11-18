<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Http\ExceptionHandler;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * A client-facing failure thrown inside a template keeps the status it chose,
 * even though kaly-tpl wraps every application exception in a ViewException.
 */
final class ViewHttpFailureTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    public function testAnHttpExceptionThrownInATemplateKeepsItsStatus(): void
    {
        $app = $this->app();

        $response = $app->handle($this->request('/test-module/index/throwing-view/'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', (string) $response->getBody());
    }

    public function testAnHttpExceptionThrownInATemplateKeepsItsStatusForJsonClients(): void
    {
        $app = $this->app();

        $response = $app->handle($this->request('/test-module/index/throwing-view/', 'application/json'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(ExceptionHandler::PROBLEM_JSON, $response->getHeaderLine('Content-Type'));
    }

    private function app(): App
    {
        return App::create(__DIR__)->routing(TrailingSlash::Add, true)->debug(false);
    }

    private function request(string $path, string $accept = 'text/html'): ServerRequestInterface
    {
        return HttpFactory::createRequestFromGlobals()->withUri(new Uri($path))->withHeader('Accept', $accept);
    }
}
