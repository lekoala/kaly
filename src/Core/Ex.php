<?php

declare(strict_types=1);

namespace Kaly\Core;

use Exception;

/**
 * Helper base exception class
 *
 * For failures that are the application's or the developer's, not the client's:
 * a bad config, a missing module, a broken wiring. It carries no HTTP status,
 * the client-facing failures extend `Kaly\Http\HttpException` instead.
 *
 * Exception messages should be one sentence, not ending with a .
 */
class Ex extends Exception {}
