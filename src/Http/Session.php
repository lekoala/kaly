<?php

declare(strict_types=1);

namespace Kaly\Http;

/**
 * BC alias of NativePhpSession.
 *
 * Kept so existing code (`new Session()`, `Session::configureDefaults()`,
 * `HttpContext::session()`) keeps working. New code should type against
 * SessionInterface and pick the storage that matches its runtime:
 * NativePhpSession for sequential execution, ArraySession (or another
 * request-scoped implementation) for concurrent runtimes.
 */
class Session extends NativePhpSession {}
