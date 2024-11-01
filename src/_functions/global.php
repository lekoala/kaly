<?php

// This file is always included by the autoloader
// These are helpers function that are only declared if they don't exist
// They are not required by the framework that should work without them

if (!function_exists('t')) {
    /**
     * @param array<string, mixed> $parameters
     */
    function t(string $message, array $parameters = [], string $domain = null, string $locale = null): string
    {
        static $translator = null;
        if ($translator === null) {
            $translator = \Kaly\Core\App::inst()->getInjector()->make(\Kaly\Text\Translator::class);
        }
        return $translator->translate($message, $parameters, $domain, $locale);
    }
}

if (!function_exists('is_cli')) {
    function is_cli(): bool
    {
        // false will be returned if response_code is not provided
        // and it is not invoked in a web server environment (such as from a CLI application).
        return php_sapi_name() === 'cli' || !http_response_code();
    }
}

if (!function_exists('d')) {
    /**
     * Dump in a worker context means throwing an exception
     * @link https://github.com/avto-dev/stacked-dumper-laravel/blob/master/functions/dump.php
     * @param array<mixed> ...$vars
     */
    function d(...$vars): void
    {
        // You can override with your own settings
        $ph = $_ENV['DUMP_IDE_PLACEHOLDER'] ?? 'vscode://file/{file}:{line}:0';
        $ex = $_ENV['DUMP_EXCEPTION'] ?? false;

        // Get caller info
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);

        // Check origin
        $backtrace[0] ??= [];
        $file = $backtrace[0]['file'] ?? '(undefined file)';
        $line = $backtrace[0]['line'] ?? 0;

        // Extract arguments
        $arguments = [];
        if ($line > 0) {
            $src = @file($file);
            if ($src) {
                // Find all arguments, ignore variables within parenthesis if it's on one line
                preg_match("/" . __FUNCTION__ . "\((.+)\)/", $src[$line - 1], $matches);
                if (!empty($matches[1])) {
                    $split = preg_split("/(?![^(]*\)),/", $matches[1]);
                    if ($split) {
                        $arguments = array_map('trim', $split);
                    }
                }
            }
        }

        // Output content, store in a variable in case we need to send a response instead
        ob_start();
        $is_cli = is_cli();

        // show location
        if ($is_cli || !is_string($ph)) {
            echo str_repeat('=', 20) . "\n";
            echo "$file:$line\n";
        } else {
            $link = str_replace(['{file}', '{line}'], [$file, $line], $ph);
            echo "<pre><a href=\"$link\">$file:$line</a></pre>";
        }

        // show values with their argument name
        $fn = function_exists('dump') ? 'dump' : 'var_dump';
        foreach ($vars as $i => $v) {
            $name = $arguments[$i] ?? null;

            if ($is_cli) {
                echo str_repeat('-', 20) . "\n";
                echo "$name\n";
                var_dump($v); // don't use dump in cli
            } else {
                echo "<pre>$name</pre>";
                $fn($v);
            }
        }

        if ($is_cli) {
            echo str_repeat('=', 20) . "\n";
        }

        $content = ob_get_contents();
        ob_end_clean();

        if ($ex) {
            throw new \Kaly\Http\ResponseException($content ?: "(no content)");
        } else {
            echo $content;
            exit(1);
        }
    }
}

if (!function_exists('l')) {
    /**
     * @param array<string, mixed> $context
     */
    function l(mixed $message, array $context = []): void
    {
        if (!is_string($message)) {
            $message = \Kaly\Util\Str::stringify($message);
        }
        $logger = \Kaly\Core\App::inst()->getInjector()->make(
            \Psr\Log\LoggerInterface::class,
            \Kaly\Core\App::DEBUG_LOGGER
        );
        $logger->log(\Psr\Log\LogLevel::DEBUG, $message, $context);
    }
}

if (!function_exists('env')) {
    /**
     * Get env value
     */
    function env(string $name): string
    {
        return \Kaly\Util\Env::getString($name);
    }
}
