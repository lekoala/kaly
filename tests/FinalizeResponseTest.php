<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\HttpContext;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class FinalizeResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    private function app(): App
    {
        $app = new App(__DIR__);
        $app->boot();
        return $app;
    }

    private function finalize(App $app, callable $finalize): void
    {
        $app->addCallback(App::CB_FINALIZE_RESPONSE, $finalize);
    }

    public function testFinalizerRunsOnHappyPath(): void
    {
        $app = $this->app();
        $this->finalize($app, static fn(ResponseInterface $response, HttpContext $ctx): ResponseInterface => $response->withHeader(
            'X-Audit',
            'yes',
        ));

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/foo/'));
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('yes', $response->getHeaderLine('X-Audit'));
    }

    public function testFinalizerRunsOnErrorResponses(): void
    {
        $app = $this->app();
        $this->finalize($app, static fn(ResponseInterface $response, HttpContext $ctx): ResponseInterface => $response->withHeader(
            'X-Audit',
            'yes',
        ));

        $base = HttpFactory::createRequestFromGlobals();

        // Unknown route: kernel-built 404
        $notFound = $app->handle($base->withUri(new Uri('/no-such-module/')));
        $this->assertSame(404, $notFound->getStatusCode());
        $this->assertSame('yes', $notFound->getHeaderLine('X-Audit'));

        // Throwing action: kernel-built 500
        $error = $app->handle($base->withUri(new Uri('/test-module/index/middlewareexception/')));
        $this->assertSame(500, $error->getStatusCode());
        $this->assertSame('yes', $error->getHeaderLine('X-Audit'));
    }

    public function testFinalizerSeesTheContext(): void
    {
        $app = $this->app();
        $locales = [];
        $this->finalize($app, static function (ResponseInterface $response, HttpContext $ctx) use (&$locales): ResponseInterface {
            $locales[] = $ctx->locale();
            return $response;
        });

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/foo/'));
        $app->handle($request);

        $this->assertNotSame([], $locales);
    }

    public function testThrowingFinalizerKeepsTheResponse(): void
    {
        $app = $this->app();
        $errors = 0;
        $app->addCallback(App::CB_ERROR, static function () use (&$errors): void {
            $errors++;
        });
        $this->finalize($app, static function (): ResponseInterface {
            throw new \RuntimeException('finalize failed');
        });

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/foo/'));
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('foo', (string) $response->getBody());
        $this->assertSame(1, $errors);
    }

    public function testInvalidReturnKeepsTheResponse(): void
    {
        $app = $this->app();
        $errors = 0;
        $app->addCallback(App::CB_ERROR, static function () use (&$errors): void {
            $errors++;
        });
        $this->finalize($app, static fn(): string => 'not a response');

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/foo/'));
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $errors);
    }

    public function testFinalizersRunInOrder(): void
    {
        $app = $this->app();
        $this->finalize($app, static fn(ResponseInterface $response, HttpContext $ctx): ResponseInterface => $response->withHeader(
            'X-Audit',
            'first',
        ));
        $this->finalize($app, static fn(ResponseInterface $response, HttpContext $ctx): ResponseInterface => $response->withHeader(
            'X-Audit-Two',
            $response->getHeaderLine('X-Audit'),
        ));

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/foo/'));
        $response = $app->handle($request);

        $this->assertSame('first', $response->getHeaderLine('X-Audit-Two'));
    }
}
