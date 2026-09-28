<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

final class TraceGroupMiddleware extends AbstractTraceMiddleware
{
    public const LABEL = 'group';
}
