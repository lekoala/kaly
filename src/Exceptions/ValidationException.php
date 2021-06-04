<?php

declare(strict_types=1);

namespace Kaly\Exceptions;

use RuntimeException;

/**
 * Validation error that should show as an alert or a form error
 * Nested error will be concatenated
 * It would result in a "fail" status in json
 */
class ValidationException extends RuntimeException
{
}
