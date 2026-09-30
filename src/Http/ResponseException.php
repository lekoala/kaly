<?php

declare(strict_types=1);

namespace Kaly\Http;

use Throwable;

/**
 * An exception that already is the response body (eg: the debug dump of dd()).
 *
 * Shaped responses belong to controller results (View, JsonResponse), not to
 * exceptions: this class carries no content type nor status mapping.
 */
class ResponseException extends HttpException
{
    public function __construct(string $message = '', int $code = 200, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, [], $previous);
    }
}
