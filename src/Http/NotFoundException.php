<?php

declare(strict_types=1);

namespace Kaly\Http;

use Throwable;
use Kaly\Http\HttpFactory;
use Psr\Http\Message\ResponseInterface;
use Kaly\Core\Ex;

class NotFoundException extends Ex implements ResponseProviderInterface
{
    /**
     * @param string $message
     * @param int $code
     * @param Throwable|null $previous
     */
    public function __construct($message = '', int $code = 404, ?Throwable $previous = null)
    {
        if (!$message) {
            $message =  "Not found";
        }
        parent::__construct($message, $code, $previous);
    }

    public function getResponse(): ResponseInterface
    {
        return HttpFactory::createErrorResponse($this->getIntCode());
    }
}
