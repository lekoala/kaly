<?php

declare(strict_types=1);

namespace Kaly\Http;

use Kaly\Core\Ex;
use Throwable;

class NotFoundException extends Ex implements HttpExceptionInterface
{
    /**
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct($message = '', int $code = 404, ?Throwable $previous = null)
    {
        if (!$message) {
            $message = 'Not found';
        }
        parent::__construct($message, $code, $previous);
    }

    public function getResponseHeaders(): array
    {
        return [];
    }

    public function getResponseBody(): string
    {
        return '';
    }
}
