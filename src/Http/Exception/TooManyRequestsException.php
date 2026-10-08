<?php

declare(strict_types=1);

namespace Kaly\Http\Exception;

use DateTimeInterface;
use Throwable;

/**
 * The client sent too many requests in a given amount of time.
 *
 * The body stays empty like any other client error, so the application error
 * view renders it: what to tell a throttled client is the application's
 * decision. The optional retry hint becomes a `Retry-After` header, either as
 * a number of seconds or as an absolute date.
 */
class TooManyRequestsException extends HttpException
{
    public function __construct(
        int|DateTimeInterface|null $retryAfter = null,
        string $message = '',
        int $code = 429,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message ?: 'Too many requests', $code, self::headers($retryAfter), $previous);
    }

    public function getResponseBody(): string
    {
        return '';
    }

    /**
     * @return array<string,string>
     */
    private static function headers(int|DateTimeInterface|null $retryAfter): array
    {
        if ($retryAfter === null) {
            return [];
        }
        $value = $retryAfter instanceof DateTimeInterface
            ? gmdate('D, d M Y H:i:s \G\M\T', $retryAfter->getTimestamp())
            : (string) $retryAfter;

        return ['Retry-After' => $value];
    }
}
