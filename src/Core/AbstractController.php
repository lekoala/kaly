<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Http\HttpContext;
use Kaly\Http\RedirectException;
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
     * Named redirectToRoute() rather than redirect(): controllers routable by
     * convention expose their public methods as actions, and `redirect` is far
     * too natural an action name to steal.
     *
     * @param array<string,mixed> $params Placeholders; the others become the query string
     */
    protected function redirectToRoute(string $name, array $params = [], int $code = 303): never
    {
        throw new RedirectException($this->ctx()->url($name, $params), $code);
    }
}
