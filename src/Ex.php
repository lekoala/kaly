<?php

declare(strict_types=1);

namespace Kaly;

use Exception;

/**
 * Base exception for Kaly errors.
 *
 * A bare marker: it says the failure is the framework's, the configuration's
 * or an invariant's, without claiming anything more. A non-HTTP failure
 * carries no status; the client-facing ones extend Kaly\Http\HttpException.
 */
class Ex extends Exception {}
