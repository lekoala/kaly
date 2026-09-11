<?php

declare(strict_types=1);

namespace Kaly\Http;

use Kaly\Core\Ex;
use Throwable;

/**
 * Thrown when a route exists but not for the request HTTP method.
 * Results in a 405 response with an Allow header listing valid methods.
 */
class MethodNotAllowedException extends Ex implements HttpExceptionInterface
{
    /**
     * @var string[]
     */
    protected array $allowedMethods;

    /**
     * @param string[] $allowedMethods
     */
    public function __construct(array $allowedMethods = [], string $message = '', ?Throwable $previous = null)
    {
        $this->allowedMethods = array_values(array_unique($allowedMethods));
        if (!$message) {
            $message = 'Method not allowed';
        }
        parent::__construct($message, 405, $previous);
    }

    /**
     * @return string[]
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }

    public function getResponseHeaders(): array
    {
        if (empty($this->allowedMethods)) {
            return [];
        }
        return ['Allow' => implode(', ', $this->allowedMethods)];
    }

    public function getResponseBody(): string
    {
        return '';
    }
}
