<?php

declare(strict_types=1);

namespace Kaly\Auth;

use SensitiveParameter;

/**
 * An application-selected password hashing policy using PHP's native algorithms.
 * Identity lookup, credential rules and persistence belong to the application.
 */
final readonly class PasswordHasher
{
    /** @param array<string, int> $options Algorithm-specific options */
    public function __construct(
        private string|int $algorithm = PASSWORD_DEFAULT,
        private array $options = [],
    ) {}

    public function hash(#[SensitiveParameter] string $password): string
    {
        return password_hash($password, $this->algorithm, $this->options);
    }

    public function verify(#[SensitiveParameter] string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }
}
