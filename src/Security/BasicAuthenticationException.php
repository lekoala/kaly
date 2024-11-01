<?php

declare(strict_types=1);

namespace Kaly\Security;

use Kaly\Http\HttpFactory;
use Throwable;
use Psr\Http\Message\ResponseInterface;
use Kaly\Security\Auth;
use Kaly\Core\Ex;
use Kaly\Http\ResponseProviderInterface;

/**
 * Used by basic auth or your credentials system
 */
class BasicAuthenticationException extends Ex implements ResponseProviderInterface
{
    public function __construct(string $message = "", int $code = 401, Throwable $previous = null)
    {
        if (!$message) {
            $message = t(Auth::class . ".auth_required", [], "kaly");
        }
        parent::__construct($message, $code, $previous);
    }

    public function getResponse(): ResponseInterface
    {
        $realm = t(Auth::class . ".enter_your_credentials", [], "kaly");
        $response = HttpFactory::createResponse($this->getMessage(), $this->getIntCode());
        $response = $response->withAddedHeader('WWW-Authenticate', "Basic realm=\"$realm\"");
        return $response;
    }
}
