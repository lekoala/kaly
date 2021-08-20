<?php

declare(strict_types=1);

namespace Kaly\Interfaces;

use Psr\Http\Message\ResponseInterface;

/**
 * Exceptions implementing this interface should
 * be able to return responses
 */
interface ResponseProviderInterface
{
    public function getResponse(): ResponseInterface;
}
