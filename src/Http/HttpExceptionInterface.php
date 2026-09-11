<?php

declare(strict_types=1);

namespace Kaly\Http;

/**
 * Exceptions implementing this interface carry the data needed to build
 * an HTTP response. Building the response itself is the responsibility of
 * an ExceptionHandlerInterface so that the core does not depend on a
 * concrete PSR-7 implementation.
 */
interface HttpExceptionInterface
{
    /**
     * The HTTP status code of the response
     */
    public function getIntCode(): int;

    /**
     * Headers to add to the response
     *
     * @return array<string,string>
     */
    public function getResponseHeaders(): array;

    /**
     * The raw response body
     */
    public function getResponseBody(): string;
}
