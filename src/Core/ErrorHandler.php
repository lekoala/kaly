<?php

declare(strict_types=1);

namespace Kaly\Core;

use Closure;
use ErrorException;
use Psr\Log\LoggerInterface;
use Throwable;

class ErrorHandler
{
    private static ?int $errLevel = null;
    private static ?string $displayErrors = null;
    private static ?string $displayStartupErrors = null;
    private static ?bool $debug = null;

    public static function configureDefaults(?bool $debug = null): void
    {
        // Already called
        if (self::$errLevel !== null) {
            return;
        }

        // Store previous values to restore them later
        self::$errLevel = error_reporting();
        $displayErrors = ini_get('display_errors');
        self::$displayErrors = is_string($displayErrors) ? $displayErrors : null;
        $displayStartupErrors = ini_get('display_startup_errors');
        self::$displayStartupErrors = is_string($displayStartupErrors) ? $displayStartupErrors : null;
        self::$debug = $debug ?? false;

        // Always collect every error so the handler below can convert and log
        // them. Displaying them publicly is only allowed while debugging.
        error_reporting(E_ALL);
        ini_set('display_errors', self::$debug ? '1' : '0');
        ini_set('display_startup_errors', self::$debug ? '1' : '0');

        // Convert errors to exceptions (so that we can catch trigger_error for example)
        set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): false {
            if (!(error_reporting() & $errno)) {
                return false;
            }
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
    }

    public static function isDebug(): bool
    {
        if (self::$debug !== null) {
            return self::$debug;
        }
        // Not configured yet: keep the previous php.ini driven behaviour
        return error_reporting() !== 0;
    }

    public static function restoreDefaults(): void
    {
        if (self::$errLevel !== null) {
            error_reporting(self::$errLevel);
            if (self::$displayErrors !== null) {
                ini_set('display_errors', self::$displayErrors);
            }
            if (self::$displayStartupErrors !== null) {
                ini_set('display_startup_errors', self::$displayStartupErrors);
            }
            restore_error_handler();
            self::$errLevel = null; // you can call configureDefaults again
            self::$displayErrors = null;
            self::$displayStartupErrors = null;
            self::$debug = null;
        }
    }

    /**
     * Wrap code to handle any exception. Can be used in index.php
     * to catch errors during boot process
     *
     * @param Closure $closure
     * @return void
     */
    public static function handle(Closure $closure): void
    {
        try {
            $closure();
        } catch (Throwable $ex) {
            $body = self::generateError($ex);
            self::setServerErrorCode(500);
            echo $body;
        }
    }

    /**
     * Set an error status code without ever throwing.
     *
     * Since PHP 8.5, calling http_response_code() after a
     * header('HTTP/...') status line emits a warning ("has no effect").
     * As our error handler converts warnings to ErrorException, a bare
     * call here would turn the 500 path itself into an exception - notably
     * in long-lived processes or test suites where a previous response
     * already set a status line. Guarding with headers_sent() and
     * suppressing the residual warning keeps the 500 path total.
     */
    public static function setServerErrorCode(int $code = 500): void
    {
        if (headers_sent()) {
            return;
        }
        @http_response_code($code);
    }

    /**
     * The body of an error that happens outside of a request cycle (eg: a
     * failing boot, see App::run() and self::handle()). Inside a cycle the
     * ExceptionHandler builds the response.
     *
     * Details are only shown in debug mode: HTML for a browser, text in a
     * terminal.
     */
    public static function generateError(Throwable $ex, ?LoggerInterface $logger = null): string
    {
        $logger?->error($ex->getMessage(), ['exception' => $ex]);

        if (!self::isDebug()) {
            return 'Server error';
        }

        $page = new DebugPage();
        return self::isCli() ? $page->text($ex) : $page->html($ex);
    }

    /**
     * http_response_code returns false when not invoked in a web server
     * environment (such as from a CLI application).
     */
    private static function isCli(): bool
    {
        return php_sapi_name() === 'cli' || !http_response_code();
    }
}
