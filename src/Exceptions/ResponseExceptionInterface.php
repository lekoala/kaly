<?php

declare(strict_types=1);

namespace Kaly\Exceptions;

use Psr\Http\Message\ResponseInterface;

interface ResponseExceptionInterface
{
    public function getResponse(): ResponseInterface;
}
