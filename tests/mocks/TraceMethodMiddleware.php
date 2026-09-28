<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

final class TraceMethodMiddleware extends AbstractTraceMiddleware
{
    public const LABEL = 'method';
}
