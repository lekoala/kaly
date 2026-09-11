<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http\Session;
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
        Fs::mkDir($this->savePath);
        Session::configureDefaults($this->savePath, 'KALYAUDIT');
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_abort();
        }
        session_id('');
        Fs::rmDir($this->savePath);
    }

    private function request(string $uri = 'https://example.test/'): ServerRequestInterface
    {
        return new BaseServerRequest('GET', $uri);
    }

    public function testNextRequestWithoutCookieDoesNotReuseSession(): void
    {
        $first = new Session([], $this->request());
        $first->set('user', 'AUDIT-USER-A');
        $firstId = $first->getId();
        $this->assertNotNull($firstId);
        $first->close();

        // A new request without the session cookie must start from scratch
        $second = new Session([], $this->request());
        $this->assertNotSame($firstId, $second->getId());
        $this->assertNull($second->get('user'));
        $second->discard();
    }

    public function testCookieIdFromRequestIsRestored(): void
    {
        $first = new Session([], $this->request());
        $first->set('user', 'AUDIT-USER-A');
        $id = $first->getId();
        $this->assertNotNull($id);
        $first->close();

        $cookieRequest = (new BaseServerRequest('GET', 'https://example.test/'))->withCookieParams(['KALYAUDIT' => $id]);
        $second = new Session([], $cookieRequest);
        $this->assertSame($id, $second->getId());
        $this->assertSame('AUDIT-USER-A', $second->get('user'));
        $second->destroy();
    }

    public function testConfiguringDefaultsTwiceKeepsPsr7CookieHandling(): void
    {
        // setUp configured once already. Doing it again must not hand the
        // cookie back to php (php 8.4+ warns when setting its cookie params
        // while session.use_cookies is disabled).
        Session::configureDefaults($this->savePath, 'KALYAUDIT');

        $this->assertSame('0', ini_get('session.use_cookies'));
        $this->assertSame('KALYAUDIT', session_name());
    }

    public function testCookieDefaultsComeFromConfigNotFromPhpGlobals(): void
    {
        $before = session_get_cookie_params();

        Session::configureExtra(['lifetime' => 1234, 'samesite' => 'Strict']);
        try {
            $defaults = Session::getCookieDefaults();
            $this->assertSame(1234, $defaults['lifetime']);
            $this->assertSame('Strict', $defaults['samesite']);

            // Php global state is left untouched: we build the header ourselves
            $this->assertSame($before, session_get_cookie_params());
        } finally {
            Session::configureExtra(['lifetime' => 0, 'samesite' => 'Lax']);
        }
    }

    public function testConfiguredDefaultsReachTheSetCookieHeader(): void
    {
        Session::configureExtra(['samesite' => 'Strict']);
        try {
            $session = new Session([], $this->request());
            $session->set('user', 'AUDIT-USER-A');

            $response = $session->addToResponse(HttpFactory::createResponse(), $this->request());
            $session->destroy();

            $cookie = $response->getHeaderLine('Set-Cookie');
            $this->assertStringStartsWith('KALYAUDIT=', $cookie);
            $this->assertStringContainsString('; SameSite=Strict', $cookie);
            $this->assertStringContainsString('; HttpOnly', $cookie);
            // https request, so the cookie is scoped and secured
            $this->assertStringContainsString('; Secure', $cookie);
            $this->assertStringContainsString('; Domain=example.test', $cookie);
        } finally {
            Session::configureExtra(['samesite' => 'Lax']);
        }
    }

    public function testGetNameFallsBackToConfiguredNameBeforeStart(): void
    {
        $session = new Session([], null);
        $this->assertFalse($session->isActive());
        $this->assertSame('KALYAUDIT', $session->getName());
    }

    public function testExplicitNameWins(): void
    {
        $session = new Session(['name' => 'CUSTOMNAME'], null);
        $this->assertSame('CUSTOMNAME', $session->getName());
    }
}
