<?php

declare(strict_types=1);

namespace Kaly\Core;

use Closure;
use ErrorException;
use Kaly\Util\Env;
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
            http_response_code(500);
            echo $body;
        }
    }

    /**
     * Generate actual error
     * Called statically either from self::handle or from App::handle
     *
     * @param Throwable $ex
     * @param LoggerInterface|null $logger
     * @return string
     */
    public static function generateError(Throwable $ex, ?LoggerInterface $logger = null): string
    {
        $type = $ex::class;
        $message = $ex->getMessage();
        $file = $ex->getFile();
        $line = $ex->getLine();

        $prev = $ex->getPrevious();

        if ($logger) {
            $logger->error("[{$type}] {$message} ({$file}:{$line})");
        }

        $body = 'Server error';

        // If we want error reporting, make it nice for DX
        if (self::isDebug()) {
            $body = '';
            if (self::isCli()) {
                $body .= "[{$type}] {$message} ({$file}:{$line})";
                if ($prev) {
                    $body .= "\n" . $prev->getMessage();
                }
            } else {
                // Escape everything that comes from the exception before it is
                // rendered as HTML.
                $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $pre = "<pre style='white-space: normal;max-width:120ch'>";
                $idePlaceholder = Env::getString(App::ENV_IDE_PLACEHOLDER, 'vscode://file/{file}:{line}:0');
                $ideLink = str_replace(['{file}', '{line}'], [$file, (string) $line], $idePlaceholder);
                $body .= $pre . '<code>' . $escape($type) . '</code> | <a href="' . $escape($ideLink) . '">';
                $body .= $escape($file) . ':' . $escape((string) $line) . '</a> ';
                $body .= '<h1>' . $escape($message) . '</h1>';
                if ($prev) {
                    $body .= '<br>Previous: ' . $escape($prev->getMessage());
                }
                $body .= '<br/>Trace:<br/></pre><pre>';
                $body .= $escape($ex->getTraceAsString());
                $body .= '</pre>';
            }
        }

        return $body;
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
