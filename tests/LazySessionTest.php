<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Auth\Authentication;
use Kaly\Auth\SessionAuthentication;
use Kaly\Core\ErrorHandler;
use Kaly\Http\Csrf\Csrf;
use Kaly\Http\Session\ArraySession;
use Kaly\Http\Session\ArraySessionProvider;
use Kaly\Http\Session\NativePhpSession;
use Kaly\Http\Session\NativePhpSessionProvider;
use Kaly\Http\Session\SessionProviderInterface;
use Kaly\Test\MemorySessionProvider;
use Kaly\Tests\Support\TempDir;
use Kaly\Util\Fs;
use LogicException;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LazySessionTest extends TestCase
{
    private string $savePath;
    private string $previousSavePath;
    private string $previousName;

    protected function setUp(): void
    {
        $savePath = session_save_path();
        $name = session_name();
        if ($savePath === false || $name === false) {
            throw new LogicException('Native session configuration is unavailable');
        }
        $this->previousSavePath = $savePath;
        $this->previousName = $name;
        $this->savePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-lazy-session-' . uniqid();
        Fs::ensureDir($this->savePath);
        session_save_path($this->savePath);
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        session_id('');
        $_SESSION = [];
        session_name($this->previousName);
        session_save_path($this->previousSavePath);
        TempDir::remove($this->savePath);
    }

    /** @return array<string, array{string}> */
    public static function backends(): array
    {
        return ['native' => ['native'], 'array' => ['array'], 'memory' => ['memory']];
    }

    private function provider(string $backend): SessionProviderInterface
    {
        $options = ['name' => 'LAZYSESSION'];
        return match ($backend) {
            'native' => new NativePhpSessionProvider($options),
            'array' => new ArraySessionProvider($options),
            'memory' => new MemorySessionProvider($options),
            default => throw new LogicException("Unknown test session backend '{$backend}'"),
        };
    }

    private function session(SessionProviderInterface $provider, ServerRequest $request): NativePhpSession|ArraySession
    {
        $session = $provider->create($request);
        assert($session instanceof NativePhpSession || $session instanceof ArraySession);
        return $session;
    }

    #[DataProvider('backends')]
    public function testAnonymousReadsAndRemovalsCreateNoStorageOrCookie(string $backend): void
    {
        $provider = $this->provider($backend);
        $request = (new ServerRequest('GET', '/'))->withCookieParams(['LAZYSESSION' => '']);
        $session = $this->session($provider, $request);
        // A closed previous native session can leave data in this global.
        $_SESSION = ['_auth' => 'previous-user'];

        $this->assertNull((new SessionAuthentication())->identifier($session));
        $this->assertSame('fallback', $session->get('missing', 'fallback'));
        $this->assertFalse($session->has('_auth'));
        $this->assertSame([], $session->all());
        $this->assertSame('fallback', $session->pull('missing', 'fallback'));
        $session->remove('_auth');
        $session->clear();

        $this->assertFalse($session->isActive());
        $this->assertNull($session->getId());
        $response = $provider->commit($session, $request, new Response());
        $this->assertFalse($response->hasHeader('Set-Cookie'));
        $this->assertSame([], glob($this->savePath . '/*'));
    }

    #[DataProvider('backends')]
    public function testReadThenWriteStartsStorageAndPreservesNullValuesAfterClose(string $backend): void
    {
        $provider = $this->provider($backend);
        $request = new ServerRequest('GET', '/');
        $session = $this->session($provider, $request);
        $this->assertNull($session->get('value'));
        $session->set('value', null);
        $this->assertTrue($session->isActive());
        $this->assertNotNull($session->getId());

        $response = $provider->commit($session, $request, new Response());
        $this->assertTrue($response->hasHeader('Set-Cookie'));
        $this->assertFalse($session->isActive());
        $this->assertNull($session->get('value', 'fallback'));
        $this->assertFalse($session->has('value'));
        $this->assertArrayHasKey('value', $session->all());
        $this->assertNull($session->pull('value', 'fallback'));
        $this->assertSame('fallback', $session->get('value', 'fallback'));
    }

    #[DataProvider('backends')]
    public function testCsrfValidationStaysLazyButTokenGenerationCreatesStorage(string $backend): void
    {
        $provider = $this->provider($backend);
        $request = new ServerRequest('GET', '/');
        $session = $this->session($provider, $request);
        $csrf = new Csrf();

        $this->assertFalse($csrf->validate($session, 'invalid'));
        $this->assertFalse($session->isActive());
        $token = $csrf->token($session);
        $this->assertTrue($session->isActive());
        $this->assertTrue($csrf->validate($session, $token));
        $this->assertTrue($provider->commit($session, $request, new Response())->hasHeader('Set-Cookie'));
    }

    #[DataProvider('backends')]
    public function testRegenerationCreatesStorageAfterAnAnonymousRead(string $backend): void
    {
        $provider = $this->provider($backend);
        $request = new ServerRequest('GET', '/');
        $session = $this->session($provider, $request);
        $this->assertSame([], $session->all());

        $session->regenerateId();
        $this->assertTrue($session->isActive());
        $this->assertNotNull($session->getId());
        $this->assertTrue($provider->commit($session, $request, new Response())->hasHeader('Set-Cookie'));
    }

    #[DataProvider('backends')]
    public function testLoginAndLogoutKeepDataAndRotateIds(string $backend): void
    {
        $session = $this->session($this->provider($backend), new ServerRequest('GET', '/'));
        $auth = new Authentication();
        $sessionAuth = new SessionAuthentication();
        $this->assertNull($sessionAuth->identifier($session));
        $session->set('cart', 'keep');
        $id = $session->getId();
        $csrf = new Csrf();
        $token = $csrf->token($session);

        $sessionAuth->login($session, $auth, '42', new \stdClass(), ['admin']);
        $this->assertNotSame($id, $session->getId());
        $this->assertSame('42', $sessionAuth->identifier($session));
        $this->assertTrue($auth->allows('admin'));
        $this->assertFalse($csrf->validate($session, $token));
        $id = $session->getId();

        $sessionAuth->logout($session, $auth);
        $this->assertNotSame($id, $session->getId());
        $this->assertNull($sessionAuth->identifier($session));
        $this->assertFalse($auth->isAuthenticated());
        $this->assertSame('keep', $session->get('cart'));
    }

    #[DataProvider('backends')]
    public function testReadAfterDestroyDoesNotResurrectSession(string $backend): void
    {
        $provider = $this->provider($backend);
        $session = $this->session($provider, new ServerRequest('GET', '/'));
        $session->set('value', 'old');
        $request = (new ServerRequest('GET', '/'))->withCookieParams(['LAZYSESSION' => $session->getId()]);
        $session->destroy();

        $this->assertSame('fallback', $session->get('value', 'fallback'));
        $this->assertFalse($session->has('value'));
        $this->assertSame([], $session->all());
        $this->assertFalse($session->isActive());
        $this->assertTrue($session->isDestroyed());
        $response = $provider->commit($session, $request, new Response());
        $this->assertStringContainsString('Max-Age=0', $response->getHeaderLine('Set-Cookie'));

        $session->set('value', 'new');
        $this->assertFalse($session->isDestroyed());
        $this->assertSame('new', $session->get('value'));
    }

    public function testNativeAndMemoryProvidersRestorePersistedValues(): void
    {
        foreach (['native', 'memory'] as $backend) {
            $provider = $this->provider($backend);
            $request = new ServerRequest('GET', '/');
            $first = $this->session($provider, $request);
            $first->set('_auth', '42');
            $provider->commit($first, $request, new Response());
            $request = $request->withCookieParams(['LAZYSESSION' => $first->getId()]);

            foreach (['get', 'has', 'all'] as $operation) {
                $restored = $this->session($provider, $request);
                $value = match ($operation) {
                    'get' => $restored->get('_auth'),
                    'has' => $restored->has('_auth'),
                    'all' => $restored->all(),
                };
                $this->assertSame(
                    match ($operation) {
                        'get' => '42',
                        'has' => true,
                        'all' => ['_auth' => '42'],
                    },
                    $value,
                );
                $this->assertSame($first->getId(), $restored->getId());
                $this->assertFalse($provider->commit($restored, $request, new Response())->hasHeader('Set-Cookie'));
            }
        }
    }

    public function testNativeStrictModeReplacesUnknownAndExpiredIds(): void
    {
        $provider = $this->provider('native');
        $request = new ServerRequest('GET', '/');
        $first = $this->session($provider, $request);
        $first->set('_auth', '42');
        $provider->commit($first, $request, new Response());
        $expired = $first->getId();
        unlink($this->savePath . '/sess_' . $expired);

        foreach (['attacker-chosen-id', $expired] as $id) {
            $request = $request->withCookieParams(['LAZYSESSION' => $id]);
            $session = $this->session($provider, $request);
            $this->assertNull((new SessionAuthentication())->identifier($session));
            $this->assertNotNull($session->getId());
            $this->assertNotSame($id, $session->getId());
            $this->assertTrue($provider->commit($session, $request, new Response())->hasHeader('Set-Cookie'));
        }
    }

    public function testNativeStrictModeReplacesMalformedId(): void
    {
        $provider = $this->provider('native');
        $request = (new ServerRequest('GET', '/'))->withCookieParams(['LAZYSESSION' => 'invalid/id']);
        $session = $this->session($provider, $request);
        ErrorHandler::configureDefaults(false);
        try {
            $this->assertNull($session->get('_auth'));
            $this->assertNotNull($session->getId());
            $this->assertNotSame('invalid/id', $session->getId());
            $this->assertTrue($provider->commit($session, $request, new Response())->hasHeader('Set-Cookie'));
        } finally {
            ErrorHandler::restoreDefaults();
        }
    }
}
