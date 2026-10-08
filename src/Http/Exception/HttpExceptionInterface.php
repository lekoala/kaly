<?php

declare(strict_types=1);

namespace Kaly\Http\Exception;

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
    public function status(): int;

    /**
     * Headers to add to the response
     *
     * @return array<string,string>
     */
    public function getResponseHeaders(): array;

    /**
     * The raw response body.
     *
     * A non-empty body is an explicit representation and takes precedence
     * over the application error view. Return an empty body when the
     * exception should be rendered through `Kaly\Core\ErrorViewInterface`
     * instead, as the silent client errors (`NotFoundException`,
     * `ForbiddenException`, `TooManyRequestsException`) do.
     */
    public function getResponseBody(): string;
}
