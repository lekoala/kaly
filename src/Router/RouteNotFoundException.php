<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Http\NotFoundException;

/**
 * No module resolver knows the url: an expected outcome (404), never a
 * reported error.
 *
 * The message explains why: it names classes and resolvers, so it stays out of
 * the production response body and only reaches the debug page.
 *
 * A resolver may throw it instead of returning null to say "not mine, and
 * here is why": the router moves on to the next resolver and keeps the
 * reason for the final 404.
 */
class RouteNotFoundException extends NotFoundException {}
