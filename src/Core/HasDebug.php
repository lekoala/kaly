<?php

declare(strict_types=1);

namespace Kaly\Core;

/**
 * Makes a class "debuggable"
 * Debug is driven by the APP_DEBUG env var by default, but can always be
 * forced true/false explicitly with setDebug().
 */
trait HasDebug
{
    protected bool $debug = false;

    public function getDebug(): bool
    {
        return $this->debug;
    }

    public function setDebug(bool $debug = true): static
    {
        $this->debug = $debug;
        return $this;
    }
}
