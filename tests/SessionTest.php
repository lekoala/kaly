<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\Hooks;
use Kaly\Core\HttpContext;
use Kaly\Core\Kernel;
use Kaly\Ex;
use Kaly\Http\Cookie\CookiePolicy;
use Kaly\Http\ExceptionHandlerInterface;
use Kaly\Http\Session\NativePhpSession;
use Kaly\Http\Session\NativePhpSessionProvider;
use Kaly\Tests\Support\HttpFactory;
use Kaly\Tests\Support\TempDir;
use Kaly\Util\Fs;
use Nyholm\Psr7\ServerRequest as BaseServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class SessionTest extends TestCase
{
    private string $savePath;

    protected function setUp(): void
    {
        $this->savePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-session-' . uniqid();
        Fs::ensureDir($this->savePath);
        session_save_path($this->savePath);
        session_name('KALYAUDIT');
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        session_id('');
        TempDir::remove($this->savePath);
    }

    private function request(string $uri = 'https://example.test/'): ServerRequestInterface
    {
        return new BaseServerRequest('GET', $uri);
    }

    /**
     * @param array<string,mixed> $options
     */
    private function provider(array $options = []): NativePhpSessionProvider
    {
        return new NativePhpSessionProvider($options);
    }

    public function testBuildingTheProviderMutatesNoGlobalIniState(): void
    {
        $keys = [
            'session.auto_start',
            'session.use_trans_sid',
            'session.use_cookies',
            'session.use_only_cookies',
            'session.use_strict_mode',
            'session.cache_limiter',
        ];
        $before = [];
        foreach ($keys as $key) {
            $before[$key] = ini_get($key);
        }

        new NativePhpSessionProvider();

        foreach ($keys as $key) {
            $this->assertSame($before[$key], ini_get($key), "Constructing the provider must not touch {$key}");
        }
    }

    public function testNextRequestWithoutCookieDoesNotReuseSession(): void
    {
        $provider = $this->provider();
        $first = $provider->create($this->request());
        $first->set('user', 'AUDIT-USER-A');
        $this->assertSame('AUDIT-USER-A', $first->get('user'));
        assert($first instanceof NativePhpSession);
        $firstId = $first->getId();
        $this->assertNotNull($firstId);
        $first->close();

        // A new request without the session cookie must start from scratch
        $second = $provider->create($this->request());
        assert($second instanceof NativePhpSession);
        $this->assertNotSame($firstId, $second->getId());
        $this->assertNull($second->get('user'));
        $second->discard();
    }

    public function testCookieIdFromRequestIsRestored(): void
    {
        $provider = $this->provider();
        $first = $provider->create($this->request());
        $first->set('user', 'AUDIT-USER-A');
        assert($first instanceof NativePhpSession);
        $id = $first->getId();
        $this->assertNotNull($id);
        $first->close();

        $cookieRequest = (new BaseServerRequest('GET', 'https://example.test/'))->withCookieParams(['KALYAUDIT' => $id]);
        $second = $provider->create($cookieRequest);
        assert($second instanceof NativePhpSession);
        $this->assertSame($id, $second->getId());
        $this->assertSame('AUDIT-USER-A', $second->get('user'));
        $second->destroy();
    }

    public function testPsr7CookieHandlingStaysDisabled(): void
    {
        // PHP never emits the session cookie itself: the provider writes the
        // Set-Cookie header on the PSR-7 response.
        $this->assertSame('0', ini_get('session.use_cookies'));
        $this->assertSame('KALYAUDIT', session_name());
    }

    public function testCookieDefaultsComeFromPolicyNotFromPhpGlobals(): void
    {
        $before = session_get_cookie_params();

        $session = new NativePhpSession([], new CookiePolicy(lifetime: 1234, httpOnly: true, sameSite: 'Strict'));
        $defaults = $session->getCookieParams();
        $this->assertSame(1234, $defaults['lifetime']);
        $this->assertSame('Strict', $defaults['samesite']);

        // Php global state is left untouched: we build the header ourselves
        $this->assertSame($before, session_get_cookie_params());
        $session->destroy();
    }

    public function testConfiguredPolicyReachesTheSetCookieHeader(): void
    {
        $provider = new NativePhpSessionProvider([], new CookiePolicy(lifetime: 0, httpOnly: true, sameSite: 'Strict'));
        $session = $provider->create($this->request());
        $session->set('user', 'AUDIT-USER-A');

        $response = $provider->commit($session, $this->request(), HttpFactory::createResponse());
        $session->destroy();

        $cookie = $response->getHeaderLine('Set-Cookie');
        $this->assertStringStartsWith('KALYAUDIT=', $cookie);
        $this->assertStringContainsString('; SameSite=Strict', $cookie);
        $this->assertStringContainsString('; HttpOnly', $cookie);
        // https request, so the cookie is secured — but host-only: no Domain
        // is inferred from the request, subdomains are never included by default
        $this->assertStringContainsString('; Secure', $cookie);
        $this->assertStringNotContainsString('Domain=', $cookie);
    }

    public function testExplicitCookieLifetimeSurvivesRotationOnALaterGet(): void
    {
        $provider = $this->provider(['lifetime' => 9999]);
        $post = (new BaseServerRequest('POST', 'https://example.test/'))->withParsedBody(['_remember' => '1']);
        $session = $provider->create($post);
        assert($session instanceof NativePhpSession);
        $session->set('_auth', 'user-C');
        $response = $provider->commit($session, $post, HttpFactory::createResponse());
        $this->assertStringContainsString('Max-Age=9999', $response->getHeaderLine('Set-Cookie'));

        $get = $this->request()->withCookieParams(['KALYAUDIT' => $session->getId()]);
        $restored = $provider->create($get);
        $restored->regenerateId();
        $response = $provider->commit($restored, $get, HttpFactory::createResponse());
        $this->assertStringContainsString('Max-Age=9999', $response->getHeaderLine('Set-Cookie'));
        $this->assertSame('user-C', $restored->get('_auth'));
        $restored->destroy();
    }

    public function testRememberPostFieldDoesNotChooseCookiePolicy(): void
    {
        $request = (new BaseServerRequest('POST', 'https://example.test/'))->withParsedBody(['_remember' => '1']);
        $provider = $this->provider();
        $session = $provider->create($request);
        $session->set('_auth', 'user-C');
        $response = $provider->commit($session, $request, HttpFactory::createResponse());
        $this->assertStringNotContainsString('Max-Age', $response->getHeaderLine('Set-Cookie'));
        $this->assertStringNotContainsString('Expires', $response->getHeaderLine('Set-Cookie'));
        $session->destroy();
    }

    public function testAnotherInstanceCannotReadOrReleaseTheActiveNativeSession(): void
    {
        $first = new NativePhpSession();
        $first->set('_auth', 'user-C');
        $second = new NativePhpSession();
        $this->assertFalse($second->isActive());
        $this->assertNull($second->get('_auth'));
        $this->assertFalse($second->close());
        $second->discard();
        $second->destroy();
        $this->assertTrue($first->isActive());
        $this->assertSame('user-C', $first->get('_auth'));
        $first->destroy();
    }

    public function testAnotherInstanceCannotAdoptEvenTheSameActiveId(): void
    {
        $first = new NativePhpSession();
        $first->set('_auth', 'user-C');
        $second = new NativePhpSession();
        $id = $first->getId();
        assert($id !== null);
        $second->setId($id);
        $this->expectException(Ex::class);
        $this->expectExceptionMessage('already started by PHP');
        $second->get('_auth');
    }

    public function testAnotherInstanceCannotWriteIntoTheActiveNativeSession(): void
    {
        $first = new NativePhpSession();
        $first->set('_auth', 'user-C');
        $second = new NativePhpSession();
        try {
            $second->set('_auth', 'user-B');
            $this->fail('A second native session must not adopt active storage');
        } catch (Ex $e) {
            $this->assertStringContainsString('already started by PHP', $e->getMessage());
        }
        $this->assertSame('user-C', $first->get('_auth'));
        $first->destroy();
    }

    public function testDestroyDeletesPersistedStorageWithoutAPreliminaryRead(): void
    {
        $provider = $this->provider();
        $first = $provider->create($this->request());
        $first->set('_auth', 'user-C');
        $provider->commit($first, $this->request(), HttpFactory::createResponse());
        assert($first instanceof NativePhpSession);
        $id = $first->getId();
        $request = $this->request()->withCookieParams(['KALYAUDIT' => $id]);
        $second = $provider->create($request);
        $second->destroy();
        $this->assertFileDoesNotExist($this->savePath . '/sess_' . $id);
        $replayed = $provider->create($request);
        $this->assertNull($replayed->get('_auth'));
        $replayed->destroy();
    }

    public function testDestroyDeletesPersistedStorageAfterClose(): void
    {
        $session = new NativePhpSession();
        $session->set('_auth', 'user-C');
        $id = $session->getId();
        $session->close();
        $session->destroy();
        $this->assertFileDoesNotExist($this->savePath . '/sess_' . $id);
        $replayed = new NativePhpSession();
        assert($id !== null);
        $replayed->setId($id);
        $this->assertNull($replayed->get('_auth'));
        $replayed->destroy();
    }

    public function testExpiredApplicationDataDoesNotTriggerAnImplicitRotation(): void
    {
        $provider = $this->provider();
        $first = $provider->create($this->request());
        $first->set('_auth', 'user-C');
        $first->set('_expiry', 1);
        $provider->commit($first, $this->request(), HttpFactory::createResponse());
        assert($first instanceof NativePhpSession);
        $id = $first->getId();
        $request = $this->request()->withCookieParams(['KALYAUDIT' => $id]);
        // Both in-flight requests keep the same authenticated storage and do
        // not emit competing cookies merely because time has passed.
        for ($i = 0; $i < 2; $i++) {
            $restored = $provider->create($request);
            $this->assertSame('user-C', $restored->get('_auth'));
            $this->assertSame(1, $restored->get('_expiry'));
            $response = $provider->commit($restored, $request, HttpFactory::createResponse());
            $this->assertFalse($response->hasHeader('Set-Cookie'));
        }
        $first->destroy();
    }

    public function testKernelReleasesTheSessionReopenedByATerminateHook(): void
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $request): ResponseInterface {
                $session = HttpContext::from($request)->session();
                if ($request->getMethod() === 'POST') {
                    $session->set('_auth', 'user-C');
                }
                $user = $session->get('_auth');
                return HttpFactory::createResponse()->withHeader('X-User', is_string($user) ? $user : 'anonymous');
            });
        $errors = $this->createStub(ExceptionHandlerInterface::class);
        $errors->method('toResponse')->willReturn(HttpFactory::createResponse()->withStatus(500));
        $hooks = new Hooks();
        $seen = [];
        $hooks->terminate[] = static function (HttpContext $ctx) use (&$seen): void {
            $seen[] = $ctx->session()->get('_auth');
        };
        $kernel = new Kernel($handler, $errors, $hooks, sessionProvider: $this->provider());
        $first = $kernel->handle(new BaseServerRequest('POST', 'https://example.test/'));
        $this->assertSame('user-C', $first->getHeaderLine('X-User'));
        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertSame('', session_id());
        $this->assertSame([], $_SESSION);

        $second = $kernel->handle($this->request());
        $this->assertSame('anonymous', $second->getHeaderLine('X-User'));
        $this->assertFalse($second->hasHeader('Set-Cookie'));
        $this->assertSame(['user-C', null], $seen);
    }

    public function testKernelReleasesNativeStorageWhenExceptionHandlingThrows(): void
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $request): ResponseInterface {
                HttpContext::from($request)->session()->set('_auth', 'user-C');
                throw new Ex('Pipeline failed');
            });
        $errors = $this->createStub(ExceptionHandlerInterface::class);
        $errors->method('toResponse')->willThrowException(new Ex('Error recovery failed'));
        $kernel = new Kernel($handler, $errors, sessionProvider: $this->provider());
        try {
            $kernel->handle($this->request());
            $this->fail('The exception handler must fail');
        } catch (Ex $e) {
            $this->assertSame('Error recovery failed', $e->getMessage());
        }
        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertSame('', session_id());
        $this->assertSame([], $_SESSION);
    }

    public function testKernelReleasesNativeStorageAfterAFailingTerminateHook(): void
    {
        $handler = $this->createStub(RequestHandlerInterface::class);
        $handler
            ->method('handle')
            ->willReturnCallback(static function (ServerRequestInterface $request): ResponseInterface {
                HttpContext::from($request)->session()->set('_auth', 'user-C');
                return HttpFactory::createResponse();
            });
        $errors = $this->createStub(ExceptionHandlerInterface::class);
        $errors->method('toResponse')->willReturn(HttpFactory::createResponse()->withStatus(500));
        $hooks = new Hooks();
        $hooks->terminate[] = static function (HttpContext $ctx): void {
            $ctx->session()->get('_auth');
            throw new Ex('Terminate failed');
        };
        $reported = [];
        $hooks->error[] = static function (\Throwable $error, HttpContext $ctx) use (&$reported): void {
            $reported[] = $error->getMessage();
            $ctx->session()->get('_auth');
        };
        $kernel = new Kernel($handler, $errors, $hooks, sessionProvider: $this->provider());
        $this->assertSame(200, $kernel->handle($this->request())->getStatusCode());
        $this->assertSame(['Terminate failed'], $reported);
        $this->assertSame(PHP_SESSION_NONE, session_status());
        $this->assertSame('', session_id());
    }

    public function testConstructingAnotherSessionDoesNotChangeActiveStorageConfiguration(): void
    {
        $first = new NativePhpSession(['save_path' => $this->savePath]);
        $first->set('_auth', 'user-C');
        new NativePhpSession(['save_path' => $this->savePath . '/other']);
        $this->assertSame($this->savePath, session_save_path());
        $this->assertSame('user-C', $first->get('_auth'));
        $first->destroy();
    }

    public function testGetNameFallsBackToConfiguredNameBeforeStart(): void
    {
        $session = new NativePhpSession();
        $this->assertFalse($session->isActive());
        $this->assertSame('KALYAUDIT', $session->getName());
    }

    public function testExplicitNameWins(): void
    {
        $session = new NativePhpSession(['name' => 'CUSTOMNAME']);
        $this->assertSame('CUSTOMNAME', $session->getName());
    }

    public function testRegenerateIdStartsAndRotatesAFreshSession(): void
    {
        $provider = $this->provider();
        $session = $provider->create($this->request());
        assert($session instanceof NativePhpSession);
        $this->assertFalse($session->isActive());

        $session->set('user', 'AUDIT-USER-A');
        $before = $session->getId();
        $session->regenerateId();

        $this->assertNotNull($before);
        $this->assertNotSame($before, $session->getId());
        $this->assertSame('AUDIT-USER-A', $session->get('user'));
        $session->destroy();
    }

    public function testDestroyExpiresTheClientCookieEvenBeforeStart(): void
    {
        $provider = $this->provider();
        $request = (new BaseServerRequest('GET', 'https://example.test/'))->withCookieParams(['KALYAUDIT' => 'stale-session-id']);
        $session = $provider->create($request);
        assert($session instanceof NativePhpSession);
        $this->assertFalse($session->isActive());

        $session->destroy();
        $response = $provider->commit($session, $request, HttpFactory::createResponse());

        $cookie = $response->getHeaderLine('Set-Cookie');
        $this->assertStringStartsWith('KALYAUDIT=', $cookie);
        $this->assertStringContainsString('Max-Age=0', $cookie);
    }

    public function testExplicitPolicySecureAndDomainAreNotOverriddenByTheRequest(): void
    {
        $policy = new CookiePolicy(secure: true, domain: 'example.test');
        $provider = new NativePhpSessionProvider([], $policy);
        // An http request on another host would otherwise downgrade secure and
        // steal the domain
        $session = $provider->create(new BaseServerRequest('GET', 'http://other.test/'));
        assert($session instanceof NativePhpSession);
        $params = $session->getCookieParams();
        $session->destroy();

        $this->assertTrue($params['secure']);
        $this->assertSame('example.test', $params['domain']);

        // An explicit domain is still emitted: sharing with subdomains stays
        // an explicit choice
        $session->set('user', 'AUDIT-USER-A');
        $response = $provider->commit($session, $this->request(), HttpFactory::createResponse());
        $session->destroy();

        $this->assertStringContainsString('; Domain=example.test', $response->getHeaderLine('Set-Cookie'));
    }
}
