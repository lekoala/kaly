<?php

declare(strict_types=1);

namespace Kaly\Core;

/**
 * Makes a class "debuggable"
 * To make this work out of the box, we rely on assert = dev mode
 * This way, we can do
 * assert($this->setDebug()) => debug is only enabled in dev mode
 * You can still force true/false if needed for other reasons
 */
trait HasDebug
{
    protected bool $debug = false;

    public function getDebug(): bool
    {
        return $this->debug;
    }

    /**
     * @param bool $debug
     * @return true Returns true so that calls to assert($class->setDebug()) work (assert expects true)
     */
    public function setDebug(bool $debug = true): true
    {
        $this->debug = $debug;
        return true;
    }
}
