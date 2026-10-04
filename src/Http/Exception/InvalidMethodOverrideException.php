<?php

declare(strict_types=1);

namespace Kaly\Http\Exception;

use Throwable;

/**
 * A request carries a method override that is ambiguous or not allowed.
 *
 * Results in a 400 response: an override names its target explicitly, so a
 * conflicting or unknown target is a malformed request, not a silent POST.
 */
final class InvalidMethodOverrideException extends HttpException
{
    public function __construct(string $message = '', ?Throwable $previous = null)
    {
        parent::__construct($message ?: 'Invalid method override', 400, [], $previous);
    }

    public function getResponseBody(): string
    {
        return '';
    }
}
