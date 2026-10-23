<?php

declare(strict_types=1);

namespace Kaly\Http\Exception;

final class BadRequestException extends HttpException
{
    public function __construct(string $message = 'Bad request')
    {
        parent::__construct($message, 400);
    }
}
