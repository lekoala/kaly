<?php

declare(strict_types=1);

namespace Kaly\Router;

use Throwable;
use Kaly\Core\Ex;

/**
 * Typically this is for pages not found
 */
class RouteNotFoundException extends Ex
{
    public function __construct(string $message = "", int $code = 404, Throwable $previous = null)
    {
        if (!$message) {
            $message = "Not Found";
        }
        parent::__construct($message, $code, $previous);
    }
}
