<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Closure;
use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\Hooks;
use Kaly\Core\HttpContext;
use Kaly\Core\Kernel;
use Kaly\Core\Module;
use Kaly\Ex;
use Kaly\Http\Exception\MethodNotAllowedException;
use Kaly\Http\ExceptionHandler;
use Kaly\Http\FileResponseFactory;
use Kaly\Router\Router;
use Kaly\Router\Routes;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Support\HttpFactory;
use Kaly\Tests\Support\TempDir;
use Kaly\Util\Fs;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;
use TestModule\Controller\DemoController;

/**
 * A HEAD response is the GET one without its content (RFC 9110, 9.3.2),
 * whatever produced it and whatever the runtime.
 */
class HeadTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    /**
     * @param Closure(ServerRequestInterface): ResponseInterface $produce
     */
    private function kernel(Closure $produce, Hooks $hooks = new Hooks()): Kernel
    {
        $handler = new class($produce) implements RequestHandlerInterface {
            /**
             * @param Closure(ServerRequestInterface): ResponseInterface $produce
             */
            public function __construct(
                private Closure $produce,
            ) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return ($this->produce)($request);
            }
        };
        $psr17 = new Psr17Factory();
        return new Kernel($handler, new ExceptionHandler($psr17, $psr17), $psr17, $hooks);
    }

    public function testTheContentIsDroppedAndContentLengthKept(): void
    {
        $kernel = $this->kernel(
            static fn(): ResponseInterface => new Response(
                200,
                ['Content-Length' => '123', 'Content-Type' => 'text/plain'],
                str_repeat('x', 123),
            ),
        );

        $get = $kernel->handle(new ServerRequest('GET', '/'));
        $head = $kernel->handle(new ServerRequest('HEAD', '/'));

        $this->assertSame(123, strlen((string) $get->getBody()));
        $this->assertSame('', (string) $head->getBody());
        $this->assertSame('123', $head->getHeaderLine('Content-Length'));
        $this->assertSame('text/plain', $head->getHeaderLine('Content-Type'));
    }

    public function testAMissingContentLengthIsNeverInvented(): void
    {
        $head = $this->kernel(static fn(): ResponseInterface => new Response(200, [], 'content'))->handle(new ServerRequest('HEAD', '/'));

        $this->assertSame('', (string) $head->getBody());
        $this->assertFalse($head->hasHeader('Content-Length'));
    }

    public function testTheContentIsNeverReadButReleased(): void
    {
        $content = new class(Stream::create('unreadable')) implements StreamInterface {
            public bool $closed = false;

            public function __construct(
                private StreamInterface $inner,
            ) {}

            public function __toString(): string
            {
                throw new RuntimeException('Read');
            }

            public function close(): void
            {
                $this->closed = true;
                $this->inner->close();
            }

            public function detach()
            {
                return $this->inner->detach();
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                return 0;
            }

            public function eof(): bool
            {
                return false;
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek(int $offset, int $whence = SEEK_SET): void
            {
                throw new RuntimeException('Seek');
            }

            public function rewind(): void
            {
                throw new RuntimeException('Rewind');
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write(string $string): int
            {
                throw new RuntimeException('Write');
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function read(int $length): string
            {
                throw new RuntimeException('Read');
            }

            public function getContents(): string
            {
                throw new RuntimeException('Read');
            }

            public function getMetadata(?string $key = null): mixed
            {
                return $this->inner->getMetadata($key);
            }
        };

        $head = $this->kernel(static fn(): ResponseInterface => new Response(200, [], $content))->handle(new ServerRequest('HEAD', '/'));

        $this->assertSame('', (string) $head->getBody());
        $this->assertTrue($content->closed);
    }

    public function testAnErrorResponseIsBodylessToo(): void
    {
        $kernel = $this->kernel(static fn(): never => throw new Ex('Failure'));

        $this->assertNotSame('', (string) $kernel->handle(new ServerRequest('GET', '/'))->getBody());
        $head = $kernel->handle(new ServerRequest('HEAD', '/'));
        $this->assertSame(500, $head->getStatusCode());
        $this->assertSame('', (string) $head->getBody());
    }

    public function testTerminateHooksSeeTheResponseThatLeaves(): void
    {
        $hooks = new Hooks();
        $seen = null;
        $hooks->terminate[] = static function (HttpContext $ctx) use (&$seen): void {
            $seen = (string) $ctx->response()->getBody();
        };

        $this->kernel(static fn(): ResponseInterface => new Response(200, [], 'content'), $hooks)->handle(new ServerRequest('HEAD', '/'));

        $this->assertSame('', $seen);
    }

    public function testAFileKeepsItsSizeAndItsHandleIsReleased(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-head-' . uniqid();
        Fs::putFile($dir . '/report.txt', 'twelve bytes');
        try {
            $psr17 = new Psr17Factory();
            $files = new FileResponseFactory($psr17, $psr17);
            // The application forgot to pass the method: the kernel still guarantees it
            $head = $this->kernel(static fn(): ResponseInterface => $files->create($dir . '/report.txt'))->handle(new ServerRequest(
                'HEAD',
                '/',
            ));

            $this->assertSame('12', $head->getHeaderLine('Content-Length'));
            $this->assertSame('', (string) $head->getBody());
        } finally {
            // Windows refuses to remove a file whose handle is still open
            TempDir::remove($dir);
        }
        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testConventionalActions(): void
    {
        $app = (new App(__DIR__))->routing(TrailingSlash::Add, true);
        $request = HttpFactory::createRequestFromGlobals();
        $handle = static fn(string $path, string $method): ResponseInterface => $app->handle(
            $request->withUri(new Uri($path))->withMethod($method),
        );

        // methodGet() answers HEAD without its content
        $this->assertSame('get', (string) $handle('/test-module/demo/method/', 'GET')->getBody());
        $head = $handle('/test-module/demo/method/', 'HEAD');
        $this->assertSame(200, $head->getStatusCode());
        $this->assertSame('', (string) $head->getBody());

        // A dedicated HEAD action wins over the GET one
        $this->assertSame('get', $handle('/test-module/demo/probe/', 'GET')->getHeaderLine('X-Action'));
        $this->assertSame('head', $handle('/test-module/demo/probe/', 'HEAD')->getHeaderLine('X-Action'));

        // A 404 page, rendered in debug mode, leaves without its content
        $this->assertNotSame('', (string) $handle('/test-module/nope/nope/', 'GET')->getBody());
        $missing = $handle('/test-module/nope/nope/', 'HEAD');
        $this->assertSame(404, $missing->getStatusCode());
        $this->assertSame('', (string) $missing->getBody());
    }

    public function testDeclaredGetRoutesAnswerHead(): void
    {
        $router = $this->router(static function (Routes $routes): void {
            $routes->get('/page', [DemoController::class, 'func']);
        });

        $this->assertSame('func', $this->match($router, '/page/', 'HEAD'));
        try {
            $this->match($router, '/page/', 'POST');
            $this->fail('A 405 was expected');
        } catch (MethodNotAllowedException $e) {
            $this->assertSame(['GET', 'HEAD'], $e->getAllowedMethods());
        }
    }

    public function testAnExplicitHeadRouteWinsWhateverTheOrder(): void
    {
        $getFirst = $this->router(static function (Routes $routes): void {
            $routes->get('/report', [DemoController::class, 'func']);
            $routes->map(['HEAD'], '/report', [DemoController::class, 'arr']);
        });
        $headFirst = $this->router(static function (Routes $routes): void {
            $routes->map(['HEAD'], '/report', [DemoController::class, 'arr']);
            $routes->get('/report', [DemoController::class, 'func']);
        });

        foreach ([$getFirst, $headFirst] as $router) {
            $this->assertSame('arr', $this->match($router, '/report/', 'HEAD'));
            $this->assertSame('func', $this->match($router, '/report/', 'GET'));
        }
    }

    /**
     * @param Closure(Routes): void $declare
     */
    private function router(Closure $declare): Router
    {
        $module = (new Module(__DIR__ . '/modules/TestModule'))
            ->mount('head')
            ->routes($declare);
        return new Router([$module], null, [], TrailingSlash::Add);
    }

    private function match(Router $router, string $path, string $method): string
    {
        return $router->match(
            HttpFactory::createRequestFromGlobals()
                ->withUri(new Uri('/head' . $path))
                ->withMethod($method),
        )->action;
    }
}
