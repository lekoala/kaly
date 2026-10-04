<?php

declare(strict_types=1);

namespace Kaly\Auth;

use BackedEnum;
use InvalidArgumentException;
use Kaly\Http\Session\SessionInterface;

/**
 * The session lifecycle of an authenticated identity.
 *
 * The session only keeps a reference to the identity (usually a user id),
 * never the principal itself: the application reloads the current object on
 * every request. Depends on the session and the authentication only, never
 * on the whole HTTP context.
 */
final readonly class SessionAuthentication
{
    public function __construct(
        private string $key = '_auth',
    ) {}

    public function identifier(SessionInterface $session): ?string
    {
        $value = $session->get($this->key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Permissions are prepared and validated before the session is touched:
     * a failure leaves both the session and the authentication untouched, and
     * an identifier that could never be restored is refused upfront.
     *
     * @param iterable<string|BackedEnum>|PermissionSet $permissions
     */
    public function login(
        SessionInterface $session,
        Authentication $auth,
        string $identifier,
        object $principal,
        iterable $permissions = [],
    ): void {
        if ($identifier === '') {
            throw new InvalidArgumentException('Authentication identifier must not be empty');
        }
        $set = $permissions instanceof PermissionSet ? $permissions : new PermissionSet($permissions);

        $session->regenerateId();
        $session->set($this->key, $identifier);

        $auth->authenticate($principal, $set);
    }

    public function logout(SessionInterface $session, Authentication $auth): void
    {
        $session->remove($this->key);
        $auth->clear();

        $session->regenerateId();
    }
}
