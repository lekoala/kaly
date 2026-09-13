<?php

declare(strict_types=1);

namespace Kaly\Core;

use InvalidArgumentException;

/**
 * This is a simple alternative to "event dispatchers"
 */
trait HasCallbacks
{
    /**
     * @var array<string,callable[]>
     */
    protected array $callbacks = [];

    public function addCallback(string $id, callable $callable): self
    {
        if (!self::isValidCallbackId($id)) {
            throw new InvalidArgumentException("{$id} is not a valid callback id");
        }
        $this->callbacks[$id][] = $callable;
        return $this;
    }

    public function clearCallbacks(string $id): void
    {
        unset($this->callbacks[$id]);
    }

    protected static function isValidCallbackId(string $id): bool
    {
        // Overwrite this in class using traits to validate against an actual list
        return $id !== '' ? true : false;
    }

    /**
     * @param array<string,mixed> ...$params
     */
    public function runCallbacks(string $id, ...$params): void
    {
        if (!self::isValidCallbackId($id)) {
            throw new InvalidArgumentException("{$id} is not a valid callback id");
        }
        if (empty($this->callbacks[$id])) {
            return;
        }
        foreach ($this->callbacks[$id] as $callable) {
            $callable(...$params);
        }
    }
}
