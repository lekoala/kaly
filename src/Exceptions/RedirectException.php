<?php

declare(strict_types=1);

namespace Kaly\Exceptions;

use InvalidArgumentException;
use Throwable;
use RuntimeException;

class RedirectException extends RuntimeException
{
    public const PERMANENT_REDIRECT = 301;
    public const OTHER_REDIRECT = 303;
    public const NOT_MODIFIED_REDIRECT = 304;
    public const TEMPORARY_REDIRECT = 307;

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
