<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Exceptions implementing this interface should
 * be able to return responses
 */
interface ResponseProviderInterface
{
    public function getResponse(): ResponseInterface;

    public function getIntCode(): int;
}
