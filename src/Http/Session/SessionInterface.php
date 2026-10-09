<?php

declare(strict_types=1);

namespace Kaly\Http\Session;

/**
 * Applicative session storage.
 *
 * The contract is deliberately backend-agnostic: it works the same for native
 * PHP sessions, Redis, storageless or request-scoped implementations. It knows
 * nothing about PSR-7, cookies or session ids: HTTP and persistence belong to
 * the SessionProviderInterface, which creates the session from the request and
 * writes it back to the response.
 *
 * Reading missing storage returns defaults without creating a session.
 * Removing or clearing missing storage is also a no-op. set() and
 * regenerateId() create storage when needed. A stored null is returned by
 * get() and pull(), while has() only reports non-null values.
 */
interface SessionInterface
{
    /**
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * @param mixed $value
     */
    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function remove(string $key): void;

    public function clear(): void;

    /**
     * @return mixed
     */
    public function pull(string $key, mixed $default = null): mixed;

    /**
     * @return array<string,mixed>
     */
    public function all(): array;

    /**
     * Rotate the session id, keeping the data.
     * Applicative and security-relevant, whatever the backend.
     */
    public function regenerateId(): void;

    /**
     * Drop the data and invalidate the session.
     */
    public function destroy(): void;
}
