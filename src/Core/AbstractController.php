<?php

declare(strict_types=1);

namespace Kaly\Core;

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
}
