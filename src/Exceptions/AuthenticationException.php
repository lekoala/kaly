<?php

declare(strict_types=1);

namespace Kaly\Exceptions;

use Kaly\Http;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use RuntimeException;

/**
 * Used by basic auth or your credentials system
 */
class AuthenticationException extends RuntimeException implements ResponseExceptionInterface
{
    public function __construct(string $message = "", int $code = 401, Throwable $previous = null)
    {
        if (!$message) {
            $message = "Authentication required";
        }
        parent::__construct($message, $code, $previous);
    }

    public function getResponse(): ResponseInterface
    {
        $realm = "Enter your credentials";
        $response = Http::createResponse($this->getMessage(), $this->getCode());
        $response = $response->withAddedHeader('WWW-Authenticate', "Basic realm=\"$realm\"");
        return $response;
    }
}
