<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Router\Middleware;
use Kaly\Tests\Mocks\TraceClassMiddleware;
use Kaly\Tests\Mocks\TraceMethodMiddleware;
use Kaly\Tests\Mocks\TraceParentMiddleware;

#[Middleware(TraceClassMiddleware::class)]
class GuardedController extends GuardedBaseController
{
    // Declared again on the method: it still runs once, at its outermost place
    #[Middleware(TraceMethodMiddleware::class, TraceParentMiddleware::class)]
    public function index(): string
    {
        return $this->trace();
    }

    public function open(): string
    {
        return $this->trace();
    }
}
