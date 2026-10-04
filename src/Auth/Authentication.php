<?php

declare(strict_types=1);

namespace Kaly\Auth;

use BackedEnum;
use LogicException;

/**
 * The identity established for the current request.
 *
 * The principal is any application object (User, Admin, ApiClient...): Kaly
 * never decides what a user is. Permissions are a projection built at
 * authentication time and reloaded on every request, never stored.
 */
final class Authentication
{
    private ?object $principal = null;

    private PermissionSet $permissions;

    public function __construct()
    {
        $this->permissions = new PermissionSet();
    }

    public function isAuthenticated(): bool
    {
        return $this->principal !== null;
    }

    public function principal(): object
    {
        return $this->principal ?? throw new LogicException('The request is not authenticated');
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function principalAs(string $class): object
    {
        $principal = $this->principal();

        if (!$principal instanceof $class) {
            throw new LogicException("Authenticated principal is not an instance of {$class}");
        }

        return $principal;
    }

    /**
     * Establish or replace the identity of the request.
     *
     * @param iterable<string|BackedEnum> $permissions
     */
    public function authenticate(object $principal, iterable $permissions = []): void
    {
        $this->principal = $principal;
        $this->permissions = new PermissionSet($permissions);
    }

    public function allows(string|BackedEnum $permission): bool
    {
        return $this->isAuthenticated() && $this->permissions->allows($permission);
    }

    public function permissions(): PermissionSet
    {
        return $this->permissions;
    }

    public function clear(): void
    {
        $this->principal = null;
        $this->permissions = new PermissionSet();
    }
}
