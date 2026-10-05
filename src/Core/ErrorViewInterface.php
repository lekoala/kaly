<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\View\View;
use Throwable;

/** Selects a production error view; null keeps the standard error response. */
interface ErrorViewInterface
{
    /** The exception's status and headers are preserved, regardless of the view's status. */
    public function view(Throwable $exception, HttpContext $ctx, int $status): ?View;
}
