<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\HttpContext;
use Kaly\Core\Middleware\RequestIdHeader;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class RequestIdTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    public function testRequestIdIsStableWithinAContextAndUniqueAcrossContexts(): void
    {
        $request = HttpFactory::createRequestFromGlobals();
        $ctx = new HttpContext($request);
        $other = new HttpContext($request);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $ctx->requestId());
        $this->assertSame($ctx->requestId(), $ctx->requestId());
        $this->assertNotSame($ctx->requestId(), $other->requestId());
    }

    public function testHeaderMatchesTheIdSeenDuringTheCycleAndIgnoresTheClientValue(): void
    {
        $app = $this->app();
        $seen = [];
        $app->middleware()->outgoing(static function (ResponseInterface $response, HttpContext $ctx) use (&$seen): ResponseInterface {
            $seen[] = $ctx->requestId();
            return $response;
        });

        $request = HttpFactory::createRequestFromGlobals()
            ->withUri(new Uri('/test-module/index/foo/'))
            ->withHeader(RequestIdHeader::HEADER, 'forged');
        $first = $app->handle($request);
        $second = $app->handle($request);

        $this->assertSame(200, $first->getStatusCode());
        $this->assertSame($seen[0], $first->getHeaderLine(RequestIdHeader::HEADER));
        $this->assertSame($seen[1], $second->getHeaderLine(RequestIdHeader::HEADER));
        $this->assertNotSame($seen[0], $seen[1]);
        $this->assertNotSame('forged', $seen[0]);
    }

    public function testErrorResponsesCarryTheRequestId(): void
    {
        $app = $this->app();
        $base = HttpFactory::createRequestFromGlobals();

        $notFound = $app->handle($base->withUri(new Uri('/no-such-module/')));
        $error = $app->handle($base->withUri(new Uri('/test-module/index/middlewareexception/')));

        $this->assertSame(404, $notFound->getStatusCode());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $notFound->getHeaderLine(RequestIdHeader::HEADER));
        $this->assertSame(500, $error->getStatusCode());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $error->getHeaderLine(RequestIdHeader::HEADER));
    }

    private function app(): App
    {
        $app = (new App(__DIR__))->routing(TrailingSlash::Add, true);
        $app->middleware()->outgoing(RequestIdHeader::class, always: true);
        $app->boot();
        return $app;
    }
}
