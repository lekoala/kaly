<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Wraps a PSR-7 request with additional features
 * @link https://github.com/slimphp/Slim-Http/blob/master/src/ServerRequest.php
 * @link https://github.com/Nyholm/psr7
 * @link https://github.com/mako-framework/framework/blob/master/src/mako/http/Request.php
 */
class ServerRequest implements ServerRequestInterface
{
    use RequestPsr7;
    use RequestUtils;

    protected ?Session $session = null;
    protected ?Cookies $cookies = null;

    /**
     * @param ServerRequestInterface $request
     */
    final public function __construct(ServerRequestInterface $request)
    {
        $this->request = $request;
    }

    public static function createFromRequest(ServerRequestInterface $request): ServerRequest
    {
        if ($request instanceof ServerRequest) {
            return $request;
        }
        // This is safe thanks to final constructor
        return new static($request);
    }

    /**
     * Get the session object
     * Session is not actually started unless open() or set() is called
     */
    public function getCookies(): Cookies
    {
        if ($this->cookies === null) {
            $this->cookies = new Cookies($this);
        }
        return $this->cookies;
    }

    /**
     * Get the session object
     * Session is not actually started unless open() or set() is called
     */
    public function getSession(): Session
    {
        if ($this->session === null) {
            $this->session = new Session([], $this);
        }
        return $this->session;
    }
}
