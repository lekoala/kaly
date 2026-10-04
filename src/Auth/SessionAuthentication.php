<?php

declare(strict_types=1);

namespace Kaly\Auth;

use BackedEnum;
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
     * @param iterable<string|BackedEnum> $permissions
     */
    public function login(
        SessionInterface $session,
        Authentication $auth,
        string $identifier,
        object $principal,
        iterable $permissions = [],
    ): void {
        $session->regenerateId();
        $session->set($this->key, $identifier);

        $auth->authenticate($principal, $permissions);
    }

    public function logout(SessionInterface $session, Authentication $auth): void
    {
        $session->remove($this->key);
        $auth->clear();

        $session->regenerateId();
    }
}
