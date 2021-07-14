<?php

declare(strict_types=1);

namespace Kaly\Exceptions;

use Kaly\Http;
use Throwable;
use RuntimeException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Kaly\Interfaces\ResponseProviderInterface;

class RedirectException extends RuntimeException implements ResponseProviderInterface
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
     * This is used for caching purposes.
     * It tells the client that the response has not been modified,
     * so the client can continue to use the same cached version of the response.
     */
    public const NOT_MODIFIED_REDIRECT = 304;
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

    protected string $url;

    public function __construct(string $url, int $code = 307, Throwable $previous = null)
    {
        if ($code < 300 || $code > 399) {
            throw new InvalidArgumentException("$code should be between 300 and 399");
        }
        $this->url = $url;
        $message = 'You are being redirected to ' . $url;
        parent::__construct($message, $code, $previous);
    }

    public function getResponse(): ResponseInterface
    {
        return Http::createRedirectResponse($this->getUrl(), $this->getCode(), $this->getMessage());
    }

    /**
     * Get the value of url
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * Set the value of url
     * @return $this
     */
    public function setUrl(string $url)
    {
        $this->url = $url;
        return $this;
    }
}
