<?php

declare(strict_types=1);

namespace Kaly\Http;

class Method
{
    public const GET = 'GET';
    public const POST = 'POST';
    public const PUT = 'PUT';
    public const DELETE = 'DELETE';
    public const OPTIONS = 'OPTIONS';
    public const PATCH = 'PATCH';
    public const HEAD = 'HEAD';

    public const ALL = [
        self::GET,
        self::POST,
        self::PUT,
        self::DELETE,
        self::PATCH,
        self::HEAD,
        self::OPTIONS,
    ];
}
