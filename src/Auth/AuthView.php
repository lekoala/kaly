<?php

declare(strict_types=1);

namespace Kaly\Auth;

use BackedEnum;

/**
 * The read-only face of the request authentication for templates.
 *
 * Controllers and middlewares mutate Authentication, templates only observe
 * it: no authenticate() or clear() is reachable from here, and an anonymous
 * request reads as null instead of throwing.
 */
final readonly class AuthView
{
    public function __construct(
        private Authentication $auth,
    ) {}

    public function isAuthenticated(): bool
    {
        return $this->auth->isAuthenticated();
    }

    public function principal(): ?object
    {
        return $this->auth->isAuthenticated() ? $this->auth->principal() : null;
    }

    public function allows(string|BackedEnum $permission): bool
    {
        return $this->auth->allows($permission);
    }
}
