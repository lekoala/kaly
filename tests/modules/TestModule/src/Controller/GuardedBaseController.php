<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Core\AbstractController;
use Kaly\Router\Middleware;
use Kaly\Tests\Mocks\AbstractTraceMiddleware;
use Kaly\Tests\Mocks\TraceParentMiddleware;

#[Middleware(TraceParentMiddleware::class)]
abstract class GuardedBaseController extends AbstractController
{
    protected function trace(): string
    {
        $trace = $this->request->getAttribute(AbstractTraceMiddleware::ATTRIBUTE, []);
        assert(is_array($trace));

        return implode(',', $trace);
    }
}
