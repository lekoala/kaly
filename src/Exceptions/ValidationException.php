<?php

declare(strict_types=1);

namespace Kaly\Exceptions;

use Kaly\Http;
use Throwable;
use RuntimeException;
use Psr\Http\Message\ResponseInterface;
use Kaly\Interfaces\ResponseProviderInterface;

/**
 * Validation error that should show as an alert or a form error
 * Nested error will be concatenated
 * It would result in a "fail" status in json
 */
class ValidationException extends RuntimeException implements ResponseProviderInterface
{
    public function __construct(string $message, int $code = 403, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getIntCode(): int
    {
        return intval($this->getCode());
    }

    public function getResponse(): ResponseInterface
    {
        return Http::respond($this->getMessage(), $this->getIntCode());
    }
}
