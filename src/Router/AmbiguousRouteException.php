<?php

declare(strict_types=1);

namespace Kaly\Router;

/**
 * Thrown when generate() by handler matches several explicit routes.
 *
 * Never masked by the conventional fallback: one handler with several urls
 * has no canonical url, so the caller must use a route name instead.
 */
class AmbiguousRouteException extends \RuntimeException {}
