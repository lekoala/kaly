<?php

declare(strict_types=1);

namespace Kaly\Http\Exception;

/**
 * A state-changing request carries no valid CSRF token.
 *
 * A 403, like any other forbidden request: the body stays empty and the
 * existing error handling renders it as HTML or problem+json.
 */
final class InvalidCsrfTokenException extends ForbiddenException {}
