<?php

declare(strict_types=1);

namespace Kaly\Auth;

use ArrayIterator;
use BackedEnum;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * The capabilities attached to the current identity.
 *
 * A closed set: no mutation, no wildcard, no inheritance, no deny. A template
 * or a guard asks allows()/any()/all(), the application decides how the set
 * is built at authentication time.
 *
 * The set is itself iterable over the granted permission strings, so a built
 * set can be reused wherever permissions are accepted without being rebuilt.
 *
 * @implements IteratorAggregate<string>
 */
final readonly class PermissionSet implements IteratorAggregate
{
    /**
     * @var array<string,true>
     */
    private array $permissions;

    /**
     * @param iterable<string|BackedEnum> $permissions
     */
    public function __construct(iterable $permissions = [])
    {
        $granted = [];
        foreach ($permissions as $permission) {
            $granted[self::normalize($permission)] = true;
        }
        $this->permissions = $granted;
    }

    /**
     * @return Traversable<string>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_keys($this->permissions));
    }

    public function allows(string|BackedEnum $permission): bool
    {
        return isset($this->permissions[self::normalize($permission)]);
    }

    public function any(string|BackedEnum ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->allows($permission)) {
                return true;
            }
        }

        return false;
    }

    public function all(string|BackedEnum ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->allows($permission)) {
                return false;
            }
        }

        return true;
    }

    private static function normalize(string|BackedEnum $permission): string
    {
        if ($permission instanceof BackedEnum) {
            if (!is_string($permission->value)) {
                throw new InvalidArgumentException('Permission enums must be string-backed');
            }

            return $permission->value;
        }

        return $permission;
    }
}
