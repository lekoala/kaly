<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\HttpContext;
use Kaly\Di\Definitions;
use Kaly\Middleware\OutgoingMiddlewareInterface;
use Kaly\Tests\Mocks\TestOutgoing;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\AbstractLogger;

class OutgoingBandTest extends TestCase
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

    private function auditOutgoing(): OutgoingMiddlewareInterface
    {
        return new class implements OutgoingMiddlewareInterface {
            public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
            {
                return $response->withHeader('X-Audit', 'yes');
            }
        };
    }

    public function testOutgoingRunsOnTheHappyPath(): void
    {
        $app = $this->app();
        $app->middleware()->outgoing($this->auditOutgoing());

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/foo/'));
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('yes', $response->getHeaderLine('X-Audit'));
    }

    public function testOutgoingRunsOnAShortCircuitedResponse(): void
    {
        $app = $this->app();
        $app->middleware()->incoming(new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return new Response(202, [], 'short-circuited');
            }
        });
        $app->middleware()->outgoing($this->auditOutgoing());

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/foo/'));
        $response = $app->handle($request);

        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('yes', $response->getHeaderLine('X-Audit'));
    }

    public function testOutgoingRunsOnAKernelBuiltErrorResponse(): void
    {
        $app = $this->app();
        $app->middleware()->outgoing($this->auditOutgoing());

        $base = HttpFactory::createRequestFromGlobals();

        // Unknown route: the kernel builds a 404 out of the raised exception
        $notFound = $app->handle($base->withUri(new Uri('/no-such-module/')));
        $this->assertSame(404, $notFound->getStatusCode());
        $this->assertSame('yes', $notFound->getHeaderLine('X-Audit'));

        // Throwing action: kernel-built 500
        $error = $app->handle($base->withUri(new Uri('/test-module/index/middlewareexception/')));
        $this->assertSame(500, $error->getStatusCode());
        $this->assertSame('yes', $error->getHeaderLine('X-Audit'));
    }

    public function testFailingOutgoingBecomesAnErrorAndIsNotReplayed(): void
    {
        $app = $this->app();
        $runs = 0;
        $app->middleware()->outgoing(new class($runs) implements OutgoingMiddlewareInterface {
            /**
             * @param int $runs
             */
            public function __construct(
                private int &$runs,
            ) {}

            public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
            {
                $this->runs++;
                throw new \RuntimeException('webp conversion failed');
            }
        });

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/foo/'));
        $response = $app->handle($request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(1, $runs, 'a failing outgoing must not be replayed on its own error response');
    }

    public function testReturnedResponseFromOutgoingIsUsedAsIs(): void
    {
        $app = $this->app();
        $app->middleware()->outgoing(new class implements OutgoingMiddlewareInterface {
            public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
            {
                return new Response(418, ['X-Replaced' => 'yes'], 'replaced');
            }
        });

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/foo/'));
        $response = $app->handle($request);

        $this->assertSame(418, $response->getStatusCode());
        $this->assertSame('replaced', (string) $response->getBody());
    }

    public function testFailureOfOneOutgoingDiscardsEarlierTransformationsButFinalizerStillRuns(): void
    {
        $app = $this->app();
        $app->middleware()->outgoing(new class implements OutgoingMiddlewareInterface {
            public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
            {
                return $response->withHeader('X-Before-Failure', 'yes');
            }
        });
        $runs = 0;
        $app->middleware()->outgoing(new class($runs) implements OutgoingMiddlewareInterface {
            /**
             * @param int $runs
             */
            public function __construct(
                private int &$runs,
            ) {}

            public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
            {
                $this->runs++;
                throw new \RuntimeException('outgoing failed');
            }
        });
        $app->addCallback(App::CB_FINALIZE_RESPONSE, static fn(
            ResponseInterface $response,
            HttpContext $ctx,
        ): ResponseInterface => $response->withHeader('X-Final', 'kept'));

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/foo/'));
        $response = $app->handle($request);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(
            '',
            $response->getHeaderLine('X-Before-Failure'),
            'an earlier transformation of the phase is discarded on failure',
        );
        $this->assertSame('kept', $response->getHeaderLine('X-Final'), 'finalizeResponse still runs on the error response');
        $this->assertSame(1, $runs, 'the outgoing phase is attempted once, not replayed on its own error');
    }

    public function testDebugLogRecordsNormalizedValuesAfterFinalizers(): void
    {
        $logger = new class extends AbstractLogger {
            /**
             * @var list<array{level:string,message:string,context:array<string,mixed>}>
             */
            public array $records = [];

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => $message,
                    'context' => $context,
                ];
            }
        };

        $app = new App(__DIR__);
        $app->setDebug(true);
        $app->addCallback(App::CB_AFTER_DEFINITIONS, static function (Definitions $definitions) use ($logger): void {
            $definitions->set(App::DEBUG_LOGGER, $logger);
        });
        $app->boot();

        $app->middleware()->outgoing(TestOutgoing::class);
        // A finalizer changes the status after the response was produced: the
        // log must record the status that really leaves the application.
        $app->addCallback(App::CB_FINALIZE_RESPONSE, static fn(
            ResponseInterface $response,
            HttpContext $ctx,
        ): ResponseInterface => $response->withStatus(418));

        $request = HttpFactory::createRequestFromGlobals()->withUri(new Uri('/test-module/index/foo/'));
        $app->handle($request);

        $this->assertCount(1, $logger->records);
        $context = $logger->records[0]['context'];
        $this->assertSame('418', $context['status'], 'the status is a string, not an int');
        $this->assertSame([TestOutgoing::class], $context['outgoing'], 'configured outgoing middlewares are class names');
        $this->assertContains(TestOutgoing::class, $context['executed']);
    }
}
