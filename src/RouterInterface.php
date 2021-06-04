<?php

declare(strict_types=1);

namespace Kaly;

use Exception;
use Stringable;
use Nyholm\Psr7\Response;
use Kaly\Exceptions\RouterException;
use Psr\Container\ContainerInterface;
use Kaly\Exceptions\RedirectException;
use Kaly\Exceptions\ValidationException;
use Psr\Http\Message\ServerRequestInterface;

interface RouterInterface
{
    /**
     * @throws RedirectException Will be converted to redirect
     * @throws ValidationException Will be converted to 403 error
     * @throws RouterException Will be converted to 404 error
     * @throws Exception Will be converted to 500 error
     * @return Response|string|Stringable|array<string, mixed>
     */
    public function match(ServerRequestInterface $request, ContainerInterface $di = null);
}
