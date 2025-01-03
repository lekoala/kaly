<?php

declare(strict_types=1);

namespace Kaly\Http;

use Throwable;
use Psr\Http\Message\ResponseInterface;
use Kaly\Http\HttpFactory;
use Kaly\Core\Ex;
use Kaly\Http\ResponseProviderInterface;

/**
 * Validation error that should show as an alert or a form error
 * Nested error will be concatenated
 * It would result in a "fail" status in json
 */
class ValidationException extends Ex implements ResponseProviderInterface
{
    public function __construct(string $message, int $code = 403, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function getResponse(): ResponseInterface
    {
        return HttpFactory::createResponse($this->getMessage(), $this->getIntCode());
    }
}
