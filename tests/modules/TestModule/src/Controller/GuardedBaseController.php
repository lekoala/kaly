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
        if (!is_array($trace)) {
            return '';
        }

        return implode(',', array_map(static fn(mixed $value): string => is_string($value) ? $value : '', $trace));
    }
}
