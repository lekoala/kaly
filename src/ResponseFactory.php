<?php

declare(strict_types=1);

namespace Kaly;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ResponseFactoryInterface;

class ResponseFactory implements ResponseFactoryInterface
{
    public function createResponse(int $code = 200, string $reasonPhrase = ''): ResponseInterface
    {
        $class = Http::resolveResponseClass();
        return new $class($code, [], '', '1.1', $reasonPhrase);
    }
}
