<?php

declare(strict_types=1);

namespace Kaly\Http;

use Kaly\Core\Ex;
use Throwable;

/**
 * An exception that answers an HTTP response itself.
 *
 * Every HTTP failure in Kaly extends this, so a catch or a match on the family
 * is enough to cover the whole 3xx/4xx/5xx surface. The status, the extra
 * headers and the body live here: a non-HTTP failure (a bad config, a missing
 * module) is a plain `Kaly\Core\Ex` and carries no status at all.
 */
abstract class HttpException extends Ex implements HttpExceptionInterface
{
    /**
     * @param array<string,string> $headers Headers to add to the response
     */
    public function __construct(
        string $message,
        protected int $status,
        protected array $headers = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public function getIntCode(): int
    {
        return $this->status;
    }

    public function getResponseHeaders(): array
    {
        return $this->headers;
    }

    /**
     * The message is safe to show: an HTTP failure is the client's answer, not
     * a framework detail. Subclasses that must stay silent override this.
     */
    public function getResponseBody(): string
    {
        return $this->getMessage();
    }
}
