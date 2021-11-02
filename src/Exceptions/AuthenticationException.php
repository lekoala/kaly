<?php

declare(strict_types=1);

namespace Kaly\Exceptions;

use Kaly\Auth;
use Kaly\Http;
use Throwable;
use RuntimeException;
use Psr\Http\Message\ResponseInterface;
use Kaly\Interfaces\ResponseProviderInterface;

/**
 * Used by basic auth or your credentials system
 */
class AuthenticationException extends RuntimeException implements ResponseProviderInterface
{
    public function __construct(string $message = "", int $code = 401, Throwable $previous = null)
    {
        if (!$message) {
            $message = t(Auth::class . ".auth_required", [], "kaly");
        }
        parent::__construct($message, $code, $previous);
    }

    public function getIntCode(): int
    {
        return intval($this->getCode());
    }

    public function getResponse(): ResponseInterface
    {
        $realm = t(Auth::class . ".enter_your_credentials", [], "kaly");
        $response = Http::respond($this->getMessage(), $this->getIntCode());
        $response = $response->withAddedHeader('WWW-Authenticate', "Basic realm=\"$realm\"");
        return $response;
    }
}
