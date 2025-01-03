<?php

declare(strict_types=1);

namespace Kaly\Core;

use ErrorException;
use Throwable;
use Closure;
use Kaly\Text\Ollama;
use Kaly\Util\Env;
use Psr\Log\LoggerInterface;

class ErrorHandler
{
    private static ?int $errLevel = null;

    public static function configureDefaults(?bool $debug = null): void
    {
        // Already called
        if (self::$errLevel !== null) {
            return;
        }

        // Store previous level to restore it later
        self::$errLevel = error_reporting();
        $debug = assert(true);
        // Configure errors (-1 = all, 0 = none)
        error_reporting($debug ? -1 : 0);

        // Convert errors to exceptions (so that we can catch trigger_error for example)
        set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): false {
            if (!(error_reporting() & $errno)) {
                return false;
            }
            throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
    }

    public static function restoreDefaults(): void
    {
        if (self::$errLevel !== null) {
            error_reporting(self::$errLevel);
            restore_error_handler();
            self::$errLevel = null; // you can call configureDefaults again
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
            $logger->error("[$type] $message ({$file}:{$line})");
        }

        $body = 'Server error';

        // If we want error reporting, make it nice for DX
        if (error_reporting() === -1) {
            $body = '';
            if (is_cli()) {
                $body .= "[$type] $message ($file:$line)";
                if ($prev) {
                    $body .= "\n" . $prev->getMessage();
                }
            } else {
                $pre = "<pre style='white-space: normal;max-width:120ch'>";
                $idePlaceholder = Env::getString(App::ENV_IDE_PLACEHOLDER, 'vscode://file/{file}:{line}:0');
                $ideLink = str_replace(['{file}', '{line}'], [$file, (string)$line], $idePlaceholder);
                $body .= "$pre<code>$type</code> | <a href=\"$ideLink\">$file:$line</a> ";
                $body .= "<h1>$message</h1>";
                if ($prev) {
                    $body .= "<br>Previous: " . $prev->getMessage();
                }
                $body .= "<br/>Trace:<br/></pre><pre>";
                $body .= $ex->getTraceAsString();
                $body .= "</pre>";

                // Generate helpful answer
                if (Env::get('ENABLE_OLLAMA')) {
                    $ollama = new Ollama();
                    $prompt = "Explain in one sentence how to solve this PHP error: $message. Provide a code example.";
                    $prompt .= "The source code is :\n" . file_get_contents($file);
                    $aiHelp = $ollama->generate($prompt)['response'];
                    $aiHelp = preg_replace(
                        "/```(\w*?)\n((.|\n)*?)```/",
                        "<code style='background:#eee;padding:1em;margin:1rem 0;display:block;'>$2</code>",
                        $aiHelp
                    );
                    $body .= "$pre<h2>How to solve?</h2>$aiHelp</pre>";
                }
            }
        }

        return $body;
    }
}
