<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Core\Ex;
use Throwable;

/**
 * A url could not be generated: unknown route name, missing parameter, no
 * module for that controller, invalid locale.
 *
 * This is the generation half of the router, and it fails for a reason
 * different from configuration: everything matched fine at boot, the caller
 * asked for a url that does not exist. Because it extends `Ex`, one
 * `catch (Ex)` covers both a broken config and a broken url.
 */
class RouteGenerationException extends Ex
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
