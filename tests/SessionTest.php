<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http\Cookie\CookiePolicy;
use Kaly\Http\Session\NativePhpSession;
use Kaly\Http\Session\NativePhpSessionProvider;
use Kaly\Tests\Support\HttpFactory;
use Kaly\Util\Fs;
use Nyholm\Psr7\ServerRequest as BaseServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

class SessionTest extends TestCase
{
    private string $savePath;

    protected function setUp(): void
    {
        $this->savePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kaly-session-' . uniqid();
        Fs::ensureDir($this->savePath);
        NativePhpSession::configureForPsr7();
        session_save_path($this->savePath);
        session_name('KALYAUDIT');
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        session_id('');
        self::removeDir($this->savePath);
    }

    /**
     * Delete a temp directory recursively (test-only helper).
     */
    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }

    private function request(string $uri = 'https://example.test/'): ServerRequestInterface
    {
        return new BaseServerRequest('GET', $uri);
    }

    private function provider(array $options = []): NativePhpSessionProvider
    {
        return new NativePhpSessionProvider($options);
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
        // https request, so the cookie is scoped and secured
        $this->assertStringContainsString('; Secure', $cookie);
        $this->assertStringContainsString('; Domain=example.test', $cookie);
    }

    public function testRememberMeExtendsTheCookieLifetime(): void
    {
        $provider = $this->provider(['remember_lifetime' => 9999]);
        $post = (new BaseServerRequest('POST', 'https://example.test/'))->withParsedBody(['_remember' => '1']);
        $session = $provider->create($post);
        assert($session instanceof NativePhpSession);
        $this->assertSame(9999, $session->getCookieParams()['lifetime']);
        $session->destroy();

        $get = new BaseServerRequest('GET', 'https://example.test/');
        $plain = $provider->create($get);
        assert($plain instanceof NativePhpSession);
        $this->assertSame(0, $plain->getCookieParams()['lifetime']);
        $plain->destroy();

        // An object body (eg: JSON parsed without assoc) must not fatal
        $postWithObjectBody = (new BaseServerRequest('POST', 'https://example.test/'))->withParsedBody(new \stdClass());
        $objectSession = $provider->create($postWithObjectBody);
        assert($objectSession instanceof NativePhpSession);
        $this->assertSame(0, $objectSession->getCookieParams()['lifetime']);
        $objectSession->destroy();
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
    }
}
