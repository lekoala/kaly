<?php

declare(strict_types=1);

namespace Kaly\Core;

use Exception;

/**
 * Helper base exception class
 *
 * Exception messages should be one sentence, not ending with a .
 */
class Ex extends Exception
{
    /**
     * Strictly typed getCode variant
     * @return int
     */
    public function getIntCode(): int
    {
        return intval($this->getCode());
    }
}
