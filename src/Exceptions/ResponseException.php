<?php

declare(strict_types=1);

namespace Kaly\Exceptions;

use Kaly\Http;
use RuntimeException;
use Psr\Http\Message\ResponseInterface;
use Kaly\Interfaces\ResponseProviderInterface;

class ResponseException extends RuntimeException implements ResponseProviderInterface
{
    public function getResponse(): ResponseInterface
    {
        return Http::createHtmlResponse($this->getMessage(), 200);
    }

    public function getIntCode(): int
    {
        return intval($this->getCode());
    }
}
