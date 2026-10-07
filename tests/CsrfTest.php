<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\HttpContext;
use Kaly\Core\Middleware\CsrfMiddleware;
use Kaly\Core\Module;
use Kaly\Http\Csrf\Csrf;
use Kaly\Http\Exception\InvalidCsrfTokenException;
use Kaly\Http\Session\ArraySession;
use Kaly\Router\ResolverInterface;
use Kaly\Router\Route;
use Kaly\Router\Router;
use Kaly\Router\RouteRequest;
use Kaly\Router\Routes;
use Kaly\Router\RouteScope;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Mocks\DispatcherController;
use Kaly\Tests\Mocks\TraceClassMiddleware;
use Kaly\Tests\Mocks\TraceMethodMiddleware;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * One ASCII secret per session, masked per render; the scope middlewares
 * merge in front of every route the scope resolves, whatever the resolver.
 */
class CsrfTest extends TestCase
{
    public function testTwoRendersDifferButBothValidate(): void
    {
        $csrf = new Csrf();
        $session = new ArraySession();

        $first = $csrf->token($session);
        $second = $csrf->token($session);

        $this->assertNotSame($first, $second);
        $this->assertTrue($csrf->validate($session, $first));
        $this->assertTrue($csrf->validate($session, $second));
    }

    public function testMalformedTokensAreRejected(): void
    {
        $csrf = new Csrf();
        $session = new ArraySession();
        $token = $csrf->token($session);

        $this->assertFalse($csrf->validate($session, ''));
        $this->assertFalse($csrf->validate($session, '!!!'));
        $this->assertFalse($csrf->validate(new ArraySession(), $token));

        // The first character always encodes full bits: any change alters
        // the decoded bytes and must invalidate the token.
        $tampered = $token;
        $tampered[0] = $tampered[0] === 'A' ? 'B' : 'A';
        $this->assertFalse($csrf->validate($session, $tampered));
    }

    public function testRefreshInvalidatesPreviousTokens(): void
    {
        $csrf = new Csrf();
        $session = new ArraySession();
        $old = $csrf->token($session);

        $new = $csrf->refresh($session);

        $this->assertTrue($csrf->validate($session, $new));
        $this->assertFalse($csrf->validate($session, $old));

        $csrf->clear($session);
        $this->assertFalse($csrf->validate($session, $new));
    }

    public function testCorruptSecretIsReplacedOnNextToken(): void
    {
        $csrf = new Csrf();
        $session = new ArraySession();
        $session->set(Csrf::SESSION_KEY, 'short');

        $token = $csrf->token($session);

        $this->assertTrue($csrf->validate($session, $token));
    }

    public function testObjectBodyCarriesTheToken(): void
    {
        $csrf = new Csrf();
        $session = new ArraySession();
        $valid = $csrf->token($session);
        $middleware = new CsrfMiddleware($csrf);

        $response = $middleware->process($this->requestWithSession('POST', $session, (object) ['_csrf' => $valid]), new NextHandler());
        $this->assertSame(200, $response->getStatusCode());

        $this->expectException(InvalidCsrfTokenException::class);
        $middleware->process($this->requestWithSession('POST', $session, (object) ['_csrf' => 'bogus'], $valid), new NextHandler());
    }

    public function testSafeMethodsPassWithoutContext(): void
    {
        $handler = new NextHandler();
        $middleware = new CsrfMiddleware(new Csrf());

        foreach (['GET', 'HEAD', 'OPTIONS'] as $method) {
            $response = $middleware->process(new ServerRequest($method, '/'), $handler);
            $this->assertSame(200, $response->getStatusCode());
        }
    }

    public function testUnsafeMethodWithoutTokenIsForbidden(): void
    {
        $session = new ArraySession();
        $request = $this->requestWithSession('POST', $session);

        $this->expectException(InvalidCsrfTokenException::class);
        (new CsrfMiddleware(new Csrf()))->process($request, new NextHandler());
    }

    public function testBodyBeatsHeaderEvenWhenHeaderIsValid(): void
    {
        $csrf = new Csrf();
        $session = new ArraySession();
        $valid = $csrf->token($session);
        $middleware = new CsrfMiddleware($csrf);

        $body = $middleware->process($this->requestWithSession('POST', $session, ['_csrf' => $valid]), new NextHandler());
        $this->assertSame(200, $body->getStatusCode());

        $header = $middleware->process($this->requestWithSession('POST', $session, [], $valid), new NextHandler());
        $this->assertSame(200, $header->getStatusCode());

        try {
            $middleware->process($this->requestWithSession('POST', $session, ['_csrf' => 'bogus'], $valid), new NextHandler());
            $this->fail('Conflicting body token should have been rejected');
        } catch (InvalidCsrfTokenException $e) {
            $this->assertSame(403, $e->status());
        }
    }

    public function testScopeMiddlewaresWrapCustomResolvers(): void
    {
        $resolver = new class implements ResolverInterface {
            public function resolve(RouteRequest $request): Route
            {
                return $request->route(DispatcherController::class, 'stringResult', middlewares: [TraceMethodMiddleware::class]);
            }
        };
        $scope = new StubScope($resolver, [TraceClassMiddleware::class]);
        $route = (new Router([$scope], null, [], TrailingSlash::Add))->match(new ServerRequest('GET', '/shop/widget/'));

        $this->assertSame([TraceClassMiddleware::class, TraceMethodMiddleware::class], $route->middlewares);
    }

    public function testScopeMiddlewaresAreDeduplicated(): void
    {
        $resolver = new class implements ResolverInterface {
            public function resolve(RouteRequest $request): Route
            {
                return $request->route(DispatcherController::class, 'stringResult', middlewares: [TraceClassMiddleware::class]);
            }
        };
        $scope = new StubScope($resolver, [TraceClassMiddleware::class]);
        $route = (new Router([$scope], null, [], TrailingSlash::Add))->match(new ServerRequest('GET', '/shop/widget/'));

        $this->assertSame([TraceClassMiddleware::class], $route->middlewares);
    }

    public function testScopeMiddlewaresCoverTablesAndClaims(): void
    {
        $module = new Module(__DIR__ . '/data');
        $module
            ->mount('shop')
            ->middleware(TraceClassMiddleware::class)
            ->routes(static function (Routes $routes): void {
                $routes->get('/widget', [DispatcherController::class, 'stringResult']);
            })
            ->claim('/promo', static function (Routes $routes): void {
                $routes->get('/sale', [DispatcherController::class, 'stringResult']);
            });

        $router = new Router([$module], null, [], TrailingSlash::Add);

        $table = $router->match(new ServerRequest('GET', '/shop/widget/'));
        $this->assertSame([TraceClassMiddleware::class], $table->middlewares);

        $claim = $router->match(new ServerRequest('GET', '/promo/sale/'));
        $this->assertSame([TraceClassMiddleware::class], $claim->middlewares);
    }

    /**
     * @param array<string,mixed>|object $body
     */
    private function requestWithSession(
        string $method,
        ArraySession $session,
        array|object $body = [],
        string $header = '',
    ): ServerRequestInterface {
        $ctx = new HttpContext(new ServerRequest($method, '/'));
        $ctx->useSession($session);
        $request = (new ServerRequest($method, '/'))
            ->withParsedBody($body)
            ->withAttribute(HttpContext::ATTRIBUTE, $ctx);

        return $header === '' ? $request : $request->withHeader(Csrf::HEADER, $header);
    }
}

final class NextHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return new Response(200);
    }
}

final class StubScope implements RouteScope
{
    /**
     * @param list<class-string> $middlewares
     */
    public function __construct(
        private ResolverInterface $resolver,
        private array $middlewares,
    ) {}

    public function id(): string
    {
        return 'shop';
    }

    public function getNamespace(): string
    {
        return 'Shop';
    }

    /**
     * @return array<string,string>
     */
    public function getMount(): array
    {
        return ['*' => 'shop'];
    }

    public function isLocalized(): bool
    {
        return false;
    }

    public function hasConventionRouting(): bool
    {
        return false;
    }

    /**
     * @return list<array{priority:int,resolver:ResolverInterface}>
     */
    public function resolvers(): array
    {
        return [['priority' => 0, 'resolver' => $this->resolver]];
    }

    /**
     * @return list<array{prefix:array<string,string>,routes:\Kaly\Router\RoutesDeclaration}>
     */
    public function claims(): array
    {
        return [];
    }

    /**
     * @return list<class-string>
     */
    public function middlewares(): array
    {
        return $this->middlewares;
    }
}
