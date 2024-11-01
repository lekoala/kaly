<?php

declare(strict_types=1);

namespace Kaly\Text;

use Kaly\Core\Ex;
use Throwable;

class TextException extends Ex
{
    /**
     * @param string|array<mixed> $message
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct(string|array $message, int $code = 0, ?Throwable $previous = null)
    {
        // You can pass an array as first argument
        if (is_array($message)) {
            $str = array_shift($message);
            assert(is_string($str));
            //@phpstan-ignore-next-line
            $message = vsprintf($str, $message);
        }
        parent::__construct($message, $code, $previous);
    }
}
