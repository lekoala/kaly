<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Renders an exception as a debug page. The contract lives here because the
 * exception handler needs it; the default implementation (Kaly\Core\DebugPage)
 * enriches it with the request cycle (route, locale, middlewares that ran).
 */
interface DebugPageInterface
{
    public function html(Throwable $exception, ?ServerRequestInterface $request = null, int $status = 500): string;
}
