<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Http\Exception\RedirectException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Convenience base class for controllers.
 *
 * Controllers are instantiated per request by the injector: any extra
 * constructor dependency is autowired from the container.
 */
abstract class AbstractController
{
    protected ServerRequestInterface $request;

    public function __construct(ServerRequestInterface $request)
    {
        $this->request = $request;
    }

    /**
     * The context of the current request cycle: route, locale, response...
     */
    protected function ctx(): HttpContext
    {
        return HttpContext::from($this->request);
    }

    /**
     * Redirect to a named route, generated for the locale of the request.
     *
     * @param array<string,mixed> $params Placeholders; the others become the query string
     */
    protected function redirectToRoute(string $name, array $params = [], int $code = 303): never
    {
        throw new RedirectException($this->ctx()->url($name, $params), $code);
    }
}
