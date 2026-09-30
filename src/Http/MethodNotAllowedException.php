<?php

declare(strict_types=1);

namespace Kaly\Http;

use Throwable;

/**
 * Thrown when a route exists but not for the request HTTP method.
 * Results in a 405 response with an Allow header listing valid methods.
 */
class MethodNotAllowedException extends HttpException
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
        $headers = $this->allowedMethods === [] ? [] : ['Allow' => implode(', ', $this->allowedMethods)];

        parent::__construct($message ?: 'Method not allowed', 405, $headers, $previous);
    }

    /**
     * @return string[]
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }

    public function getResponseBody(): string
    {
        return '';
    }
}
