<?php

declare(strict_types=1);

namespace Kaly\Interfaces;

use Exception;
use Kaly\Exceptions\NotFoundException;
use Kaly\Exceptions\RedirectException;
use Kaly\Exceptions\ValidationException;
use Kaly\Exceptions\AuthenticationException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Routers should implement a simple "match" function
 * that should return something that is processable
 * by our app
 */
interface RouterInterface
{
    /**
     * @throws AuthenticationException Will be converted to 401 error
     * @throws RedirectException Will be converted to 3xx redirect
     * @throws ValidationException Will be converted to 403 error
     * @throws NotFoundException Will be converted to 404 error
     * @throws Exception Will be converted to 500 error
     * @return array<string, mixed>
     */
    public function match(ServerRequestInterface $request);
}
