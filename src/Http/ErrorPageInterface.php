<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/** Optional production HTML fallback, independent of the Kaly runtime. */
interface ErrorPageInterface
{
    public function html(Throwable $exception, ServerRequestInterface $request, int $status): ?string;
}
