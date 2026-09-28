<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

final class TraceParentMiddleware extends AbstractTraceMiddleware
{
    public const LABEL = 'parent';
}
