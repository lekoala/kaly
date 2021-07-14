<?php

declare(strict_types=1);

namespace Kaly\Interfaces;

use Psr\Http\Message\ResponseInterface;

interface ResponseProviderInterface
{
    public function getResponse(): ResponseInterface;
}
