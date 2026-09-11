<?php

declare(strict_types=1);

namespace Kaly\Security;

use Kaly\Core\Ex;
use Kaly\Http\HttpFactory;
use Kaly\Http\ResponseProviderInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Used by basic auth or your credentials system
 */
class BasicAuthenticationException extends Ex implements ResponseProviderInterface
{
    public function __construct(string $message = '', int $code = 401, ?Throwable $previous = null)
    {
        if (!$message) {
            $message = t(Auth::class . '.auth_required', [], 'kaly');
        }
        parent::__construct($message, $code, $previous);
    }

    public function getResponse(): ResponseInterface
    {
        $realm = t(Auth::class . '.enter_your_credentials', [], 'kaly');
        $response = HttpFactory::createResponse($this->getMessage(), $this->getIntCode());
        return $response->withAddedHeader('WWW-Authenticate', "Basic realm=\"$realm\"");
    }
}
