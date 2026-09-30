<?php

declare(strict_types=1);

namespace Kaly\Http;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;
use Throwable;

class RedirectException extends HttpException
{
    /**
     * The URL of the requested resource has been changed permanently.
     * The new URL is given in the response.
     */
    public const MOVED_PERMANENTLY_REDIRECT = 301;
    /**
     * This response code means that the URI of requested resource has been changed temporarily.
     * Further changes in the URI might be made in the future.
     * Therefore, this same URI should be used by the client in future requests.
     */
    public const FOUND_REDIRECT = 302;
    /**
     * The server sent this response to direct the client to get the requested resource
     * at another URI with a GET request.
     */
    public const OTHER_REDIRECT = 303;
    /**
     * The server sends this response to direct the client to get the requested resource
     * at another URI with same method that was used in the prior request.
     * This has the same semantics as the 302 Found HTTP response code,
     * with the exception that the user agent must not change the HTTP method used:
     * If a POST was used in the first request, a POST must be used in the second request.
     */
    public const TEMPORARY_REDIRECT = 307;
    /**
     * This means that the resource is now permanently located at another URI,
     * specified by the Location: HTTP Response header.
     * This has the same semantics as the 301 Moved Permanently HTTP response code,
     * with the exception that the user agent must not change the HTTP method used:
     * If a POST was used in the first request, a POST must be used in the second request.
     */
    public const PERMANENT_REDIRECT = 308;

    /**
     * The response codes that redirect, with a Location header: everything
     * else in the 3xx range (304 Not Modified) is not a redirect and must not
     * carry one.
     */
    public const REDIRECT_CODES = [301, 302, 303, 307, 308];

    protected string $url;

    /**
     * @param string|UriInterface $url
     * @param int $code One of REDIRECT_CODES
     */
    public function __construct($url, int $code = 307, ?Throwable $previous = null)
    {
        if (!in_array($code, self::REDIRECT_CODES, true)) {
            throw new InvalidArgumentException("{$code} is not a redirect status, expected one of " . implode(', ', self::REDIRECT_CODES));
        }
        $this->url = (string) $url;

        parent::__construct('You are being redirected to ' . $url, $code, ['Location' => $this->url], $previous);
    }

    /**
     * Get the value of url
     */
    public function getUrl(): string
    {
        return $this->url;
    }
}
