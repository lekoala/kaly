<?php

declare(strict_types=1);

namespace Kaly\Core;

/**
 * This is a simple alternative to "event dispatchers"
 */
trait HasCallbacks
{
    /**
     * @var array<string,callable[]>
     */
    protected array $callbacks = [];

    /**
     */
    public function addCallback(string $id, callable $callable): self
    {
        assert(self::isValidCallbackId($id), "$id is not valid");
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
        return $id != '' ? true : false;
    }

    /**
     * @param array<string,mixed> ...$params
     */
    public function runCallbacks(string $id, ...$params): void
    {
        assert(self::isValidCallbackId($id), "$id is not valid");
        if (empty($this->callbacks[$id])) {
            return;
        }
        foreach ($this->callbacks[$id] as $callable) {
            $callable(...$params);
        }
    }
}
