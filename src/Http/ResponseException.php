<?php

declare(strict_types=1);

namespace Kaly\Http;

use Kaly\Core\Ex;

/**
 * An exception that already is the response body (eg: the debug dump of dd()).
 *
 * Shaped responses belong to controller results (View, JsonResponse), not to
 * exceptions: this class carries no content type nor status mapping.
 */
class ResponseException extends Ex implements HttpExceptionInterface
{
    public function getResponseHeaders(): array
    {
        return [];
    }

    public function getResponseBody(): string
    {
        return $this->getMessage();
    }
}
