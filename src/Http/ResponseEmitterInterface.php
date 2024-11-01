<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ResponseInterface;

interface ResponseEmitterInterface
{
    public function emit(ResponseInterface $response): bool;
}
