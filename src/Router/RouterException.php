<?php

declare(strict_types=1);

namespace Kaly\Router;

use Throwable;
use RuntimeException;

/**
 * Typically this is for pages not found
 */
class RouterException extends RuntimeException
{
    public function __construct(string $message, int $code = 404, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
