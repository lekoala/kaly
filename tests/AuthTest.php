<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Fiber;
use InvalidArgumentException;
use Kaly\Auth\Authentication;
use Kaly\Auth\AuthView;
use Kaly\Auth\PermissionSet;
use Kaly\Auth\SessionAuthentication;
use Kaly\Http\Csp\Csp;
use Kaly\Http\Csrf\Csrf;
use Kaly\Http\Session\ArraySession;
use Kaly\Http\Session\SessionInterface;
use Kaly\Tests\Mocks\OrderStatus;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Identity is established atomically: a failure leaves the previous state
 * untouched, and every request-scoped state stays on its own cycle.
 */
class AuthTest extends TestCase
{
    public function testAuthenticateEstablishesPrincipalAndPermissions(): void
    {
        $auth = new Authentication();
        $user = new \stdClass();

        $this->assertFalse($auth->isAuthenticated());

        $auth->authenticate($user, ['admin.access', OrderStatus::Active]);

        $this->assertTrue($auth->isAuthenticated());
        $this->assertSame($user, $auth->principal());
        $this->assertTrue($auth->allows('admin.access'));
        $this->assertTrue($auth->allows(OrderStatus::Active));
        $this->assertFalse($auth->allows('other'));
    }

    public function testAuthenticateReplacesIdentity(): void
    {
        $auth = new Authentication();
        $auth->authenticate(new \stdClass(), ['admin.access']);
        $next = new \stdClass();

        $auth->authenticate($next);

        $this->assertSame($next, $auth->principal());
        $this->assertFalse($auth->allows('admin.access'));
    }

    public function testAuthenticateIsAtomicWhenPermissionsFail(): void
    {
        $auth = new Authentication();
        $before = new \stdClass();
        $auth->authenticate($before, ['admin.access']);

        $failing = (static function (): \Generator {
            yield 'admin.access';

            throw new RuntimeException('provider down');
        })();

        try {
            $auth->authenticate(new \stdClass(), $failing);
            $this->fail('authenticate() should have thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('provider down', $e->getMessage());
        }

        $this->assertSame($before, $auth->principal());
        $this->assertTrue($auth->allows('admin.access'));
    }

    public function testPrincipalRequiresAuthentication(): void
    {
        $auth = new Authentication();

        $this->expectException(LogicException::class);
        $auth->principal();
    }

    public function testPrincipalAsRejectsAnotherClass(): void
    {
        $auth = new Authentication();
        $auth->authenticate(new \stdClass());

        $this->expectException(LogicException::class);
        $auth->principalAs(self::class);
    }

    public function testClearResetsIdentity(): void
    {
        $auth = new Authentication();
        $auth->authenticate(new \stdClass(), ['admin.access']);
        $auth->clear();

        $this->assertFalse($auth->isAuthenticated());
        $this->assertFalse($auth->allows('admin.access'));
    }

    public function testPermissionSetStringsAndEnums(): void
    {
        $set = new PermissionSet(['a.read', OrderStatus::Active]);

        $this->assertTrue($set->allows('a.read'));
        $this->assertTrue($set->allows(OrderStatus::Active));
        $this->assertTrue($set->any('missing', 'a.read'));
        $this->assertFalse($set->any('missing', 'other'));
        $this->assertTrue($set->all('a.read', OrderStatus::Active));
        $this->assertFalse($set->all('a.read', 'other'));
    }

    public function testPermissionSetRejectsIntBackedEnums(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PermissionSet([IntPermission::Write]);
    }

    public function testPermissionSetIsIterable(): void
    {
        $set = new PermissionSet(['b.read', 'b.write', 'b.read']);

        $this->assertSame(['b.read', 'b.write'], [...$set]);
    }

    public function testNumericPermissionsSurviveIteration(): void
    {
        $set = new PermissionSet(new PermissionSet(['42']));

        $this->assertTrue($set->allows('42'));
        $this->assertSame(['42'], [...$set]);
    }

    public function testLoginStoresIdentifierAndAuthenticates(): void
    {
        $session = new ArraySession();
        $auth = new Authentication();
        $user = new \stdClass();

        (new SessionAuthentication())->login($session, $auth, '42', $user, ['a.read']);

        $this->assertSame('42', (new SessionAuthentication())->identifier($session));
        $this->assertSame($user, $auth->principal());
        $this->assertTrue($auth->allows('a.read'));
    }

    public function testLoginAcceptsAGeneratorOnce(): void
    {
        $session = new ArraySession();
        $auth = new Authentication();

        $permissions = (static function (): \Generator {
            yield 'a.read';
            yield 'a.write';
        })();

        (new SessionAuthentication())->login($session, $auth, '42', new \stdClass(), $permissions);

        $this->assertTrue($auth->allows('a.read'));
        $this->assertTrue($auth->allows('a.write'));
    }

    public function testLoginRejectsAnEmptyIdentifier(): void
    {
        $session = new RecordingSession();
        $auth = new Authentication();

        try {
            (new SessionAuthentication())->login($session, $auth, '', new \stdClass());
            $this->fail('login() should have thrown');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Authentication identifier must not be empty', $e->getMessage());
        }

        $this->assertSame([], $session->writes);
        $this->assertSame(0, $session->rotations);
        $this->assertFalse($auth->isAuthenticated());
    }

    public function testFailedLoginLeavesSessionAndAuthUntouched(): void
    {
        $session = new RecordingSession();
        $auth = new Authentication();

        $failing = (static function (): \Generator {
            yield 'a.read';

            throw new RuntimeException('provider down');
        })();

        try {
            (new SessionAuthentication())->login($session, $auth, '42', new \stdClass(), $failing);
            $this->fail('login() should have thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('provider down', $e->getMessage());
        }

        $this->assertSame([], $session->writes);
        $this->assertFalse($auth->isAuthenticated());
    }

    public function testFailedRotationLeavesSessionAndAuthUntouched(): void
    {
        $session = new FailingRotationSession();
        $auth = new Authentication();

        try {
            (new SessionAuthentication())->login($session, $auth, '42', new \stdClass());
            $this->fail('login() should have thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('rotation failed', $e->getMessage());
        }

        $this->assertSame([], $session->writes);
        $this->assertFalse($auth->isAuthenticated());
    }

    public function testLogoutKeepsUnrelatedSessionData(): void
    {
        $session = new ArraySession();
        $auth = new Authentication();
        $sessionAuth = new SessionAuthentication();
        $sessionAuth->login($session, $auth, '42', new \stdClass());
        $session->set('cart', ['sku' => 1]);

        $sessionAuth->logout($session, $auth);

        $this->assertNull($sessionAuth->identifier($session));
        $this->assertFalse($auth->isAuthenticated());
        $this->assertSame(['sku' => 1], $session->get('cart'));
    }

    public function testIdentifierIgnoresNonUsableValues(): void
    {
        $session = new ArraySession();
        $sessionAuth = new SessionAuthentication();

        $this->assertNull($sessionAuth->identifier($session));

        $session->set('_auth', '');
        $this->assertNull($sessionAuth->identifier($session));

        $session->set('_auth', 42);
        $this->assertNull($sessionAuth->identifier($session));
    }

    public function testAuthViewReadsAnonymousAsNull(): void
    {
        $view = new AuthView(new Authentication());

        $this->assertFalse($view->isAuthenticated());
        $this->assertNull($view->principal());
        $this->assertFalse($view->allows('admin.access'));
    }

    public function testRequestStateIsIsolatedAcrossFibers(): void
    {
        $results = [];
        $run = static function (string $name, string $permission) use (&$results): void {
            $session = new ArraySession();
            $auth = new Authentication();
            $csrf = new Csrf();
            $csp = new Csp();
            (new SessionAuthentication())->login($session, $auth, $name, new \stdClass(), [$permission]);
            $token = $csrf->token($session);
            $nonce = $csp->nonce();
            Fiber::suspend();
            $results[$name] = [
                $auth->allows($permission),
                $session->get('_auth'),
                $csrf->validate($session, $token),
                $csp->nonce() === $nonce,
            ];
        };

        $fiberA = new Fiber(static fn() => $run('A', 'a.read'));
        $fiberB = new Fiber(static fn() => $run('B', 'b.read'));
        $fiberA->start();
        $fiberB->start();
        $fiberA->resume();
        $fiberB->resume();

        $this->assertSame([true, 'A', true, true], $results['A']);
        $this->assertSame([true, 'B', true, true], $results['B']);
    }
}

enum IntPermission: int
{
    case Write = 1;
}

class RecordingSession implements SessionInterface
{
    /** @var array<string,mixed> */
    public array $writes = [];

    public int $rotations = 0;

    /** @var array<string,mixed> */
    private array $data = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->writes[$key] = $value;
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    public function clear(): void
    {
        $this->data = [];
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function regenerateId(): void
    {
        $this->rotations++;
    }

    public function destroy(): void
    {
        $this->data = [];
    }
}

final class FailingRotationSession extends RecordingSession
{
    public function regenerateId(): void
    {
        throw new RuntimeException('rotation failed');
    }
}
