<?php

declare(strict_types=1);

namespace Kaly\Exceptions;

use Throwable;
use RuntimeException;

/**
 * Typically this is for pages not found
 */
class NotFoundException extends RuntimeException
{
    public function __construct(string $message = "", int $code = 404, Throwable $previous = null)
    {
        if (!$message) {
            $message = "Not Found";
        }
        parent::__construct($message, $code, $previous);
    }

    public function getIntCode(): int
    {
        return intval($this->getCode());
    }
}
