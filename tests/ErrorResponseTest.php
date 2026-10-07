<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\Module;
use Kaly\Ex;
use Kaly\Http\Accept;
use Kaly\Http\ExceptionHandler;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Support\HttpFactory;
use Kaly\Util\Json;
use Kaly\Validation\HasValidationResult;
use Kaly\Validation\ValidationResult;
use Kaly\Validation\Validator;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Error responses follow the client, explain the failure in debug mode and
 * leak nothing in production.
 */
class ErrorResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    private function get(App $app, string $path, string $accept = 'text/html'): ResponseInterface
    {
        return $app->handle(HttpFactory::createRequestFromGlobals()->withUri(new Uri($path))->withHeader('Accept', $accept));
    }

    public function testANotFoundIsExpectedNotAnError(): void
    {
        $errors = 0;
        $app = App::create(__DIR__)
            ->routing(TrailingSlash::Add, true)
            ->debug(false)
            ->onError(static function () use (&$errors): void {
                $errors++;
            });

        $response = $this->get($app, '/test-module/nope/');

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Not Found', (string) $response->getBody());
        $this->assertSame(0, $errors, 'a 404 must never reach the error trackers');
    }

    public function testAForbiddenIsA403ThatLeaksNothing(): void
    {
        $app = App::create(__DIR__)->routing(TrailingSlash::Add, true)->debug(false);

        $response = $this->get($app, '/test-module/state/forbidden/');
        $this->assertSame(403, $response->getStatusCode());
        // What the user may do is the application's decision, not the
        // framework's: the reason never reaches the client
        $this->assertSame('Forbidden', (string) $response->getBody());

        $problem = json_decode((string) $this->get($app, '/test-module/state/forbidden/', 'application/json')->getBody(), true);
        $this->assertSame(['type' => 'about:blank', 'title' => 'Forbidden', 'status' => 403], $problem);
    }

    public function testProductionLeaksNothing(): void
    {
        $app = App::create(__DIR__)->routing(TrailingSlash::Add, true)->debug(false);

        $response = $this->get($app, '/test-module/state/crash/');
        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame('Server error', (string) $response->getBody());

        $problem = json_decode((string) $this->get($app, '/test-module/state/crash/', 'application/json')->getBody(), true);
        $this->assertSame(['type' => 'about:blank', 'title' => 'Internal Server Error', 'status' => 500], $problem);
    }

    public function testTheDebugPageExplainsTheFailure(): void
    {
        $app = App::create(__DIR__)->routing(TrailingSlash::Add, true)->debug(true);

        $request = HttpFactory::createRequestFromGlobals()
            ->withUri(new Uri('/test-module/state/crash/?x=<script>'))
            ->withHeader('Accept', 'text/html')
            ->withHeader('User-Agent', '<img src=x onerror=alert(1)>');
        $response = $app->handle($request);
        $html = (string) $response->getBody();

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('RuntimeException', $html);
        // Escaped, never rendered
        $this->assertStringContainsString('&lt;b&gt;boom&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('<b>boom</b>', $html);
        // A client-controlled header is escaped too
        $this->assertStringContainsString('&lt;img', $html);
        $this->assertStringNotContainsString('<img', $html);
        // The code around the failing line and what the cycle established
        $this->assertStringContainsString('class="hit"', $html);
        $this->assertStringContainsString('TestModule\Controller\StateController::crash', $html);
    }

    public function testADebug404SaysWhyNothingMatched(): void
    {
        $app = App::create(__DIR__)->routing(TrailingSlash::Add, true)->debug(true);

        $problem = Json::decodeMap((string) $this->get($app, '/test-module/demo/func/too/many/', 'application/json')->getBody());

        $this->assertSame(404, $problem['status']);
        $this->assertIsString($problem['detail']);
        $this->assertStringContainsString("Too many parameters for action 'func'", $problem['detail']);
        $this->assertIsArray($problem['exception']);
    }

    public function testAPublicBodyIsKeptInEveryFormat(): void
    {
        $app = App::create(__DIR__)->routing(TrailingSlash::Add, true)->debug(false);

        $response = $this->get($app, '/test-module/index/validation/');
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('This value must not be blank', (string) $response->getBody());

        $response = $this->get($app, '/test-module/index/validation/', 'application/json');
        $this->assertSame(ExceptionHandler::PROBLEM_JSON, $response->getHeaderLine('Content-Type'));
        $problem = Json::decodeMap((string) $response->getBody());
        $this->assertSame(422, $problem['status']);
        $this->assertArrayNotHasKey('detail', $problem);
        $this->assertSame(
            [[
                'field' => 'name',
                'code' => 'not_blank',
                'message' => 'This value must not be blank',
            ]],
            $problem['errors'],
        );
    }

    /**
     * @return iterable<string,array{0:string,1:string,2:bool}>
     */
    public static function acceptProvider(): iterable
    {
        yield 'api client' => ['application/json', '', true];
        yield 'problem json' => ['application/problem+json', '', true];
        yield 'browser' => ['text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8', '', false];
        yield 'html preferred' => ['text/html, application/json;q=0.9', '', false];
        yield 'json preferred' => ['text/html;q=0.5, application/json', '', true];
        yield 'anything' => ['*/*', '', false];
        yield 'json body, no preference' => ['*/*', 'application/json', true];
        yield 'json body, html accepted' => ['text/html', 'application/json', false];
        // A wildcard expresses no preference: it must not tie an explicit JSON
        // entry with HTML (the default Axios/fetch header)
        yield 'json with wildcard' => ['application/json, text/plain, */*', '', true];
        yield 'json with wildcard, html explicit' => ['application/json, text/html', '', false];
        yield 'json refused' => ['application/json;q=0, */*', '', false];
        yield 'html family accepted' => ['text/*', 'application/json', false];
    }

    #[DataProvider('acceptProvider')]
    public function testJsonIsNegotiated(string $accept, string $contentType, bool $json): void
    {
        $request = new ServerRequest('GET', '/', ['Accept' => $accept]);
        if ($contentType !== '') {
            $request = $request->withHeader('Content-Type', $contentType);
        }
        $this->assertSame($json, Accept::prefersJson($request));
    }

    public function testANonHttpExceptionBecomes500WhateverItsCode(): void
    {
        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
        $handler = new ExceptionHandler($psr17, $psr17);

        $response = $handler->toResponse(new \RuntimeException('storage failed', 404));

        $this->assertSame(500, $response->getStatusCode(), 'a stray code must never become a status');
    }

    public function testANonHttpResultCarrierLeaksNothing(): void
    {
        $psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
        $handler = new ExceptionHandler($psr17, $psr17);
        $failure = new class('internal state') extends \RuntimeException implements HasValidationResult {
            public function validation(): ValidationResult
            {
                $validator = new Validator();
                $validator->notBlank('secret', '');
                return $validator->result();
            }
        };

        $request = new ServerRequest('GET', '/', ['Accept' => 'application/json']);
        $response = $handler->toResponse($failure, $request);

        $this->assertSame(500, $response->getStatusCode());
        $problem = Json::decodeMap((string) $response->getBody());
        $this->assertSame(['type' => 'about:blank', 'title' => 'Internal Server Error', 'status' => 500], $problem);
    }

    public function testAFailingConfigNamesItsModule(): void
    {
        $this->expectException(Ex::class);
        $this->expectExceptionMessage("Module 'FailingModule' config.php failed: boom");

        (new Module(__DIR__ . '/data/modules/FailingModule'))->loadConfig();
    }
}
