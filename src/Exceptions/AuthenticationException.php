<?php

declare(strict_types=1);

namespace Kaly\Exceptions;

use Kaly\Http;
use Kaly\Interfaces\ResponseProviderInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use RuntimeException;

/**
 * Used by basic auth or your credentials system
 */
class AuthenticationException extends RuntimeException implements ResponseProviderInterface
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
        $response = Http::respond($this->getMessage(), $this->getCode());
        $response = $response->withAddedHeader('WWW-Authenticate', "Basic realm=\"$realm\"");
        return $response;
    }
}
