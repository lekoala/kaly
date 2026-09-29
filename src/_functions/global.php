<?php

declare(strict_types=1);

// Optional debugging helpers, only declared if they don't exist already.
// They are not autoloaded and not required by the framework: require this file
// from your entry point if you want them.
//
// Nothing here reaches into the application: there is no global container in
// kaly. To translate, use the $i18n variable of your views or inject a
// TranslatorInterface. To log, inject a LoggerInterface.

if (!function_exists('is_cli')) {
    function is_cli(): bool
    {
        // http_response_code returns false if response_code is not provided
        // and it is not invoked in a web server environment (such as from a CLI application).
        return php_sapi_name() === 'cli' || !http_response_code();
    }
}

if (!function_exists('d')) {
    /**
     * Dump variables without stopping the execution, so it stays safe inside
     * a worker or a Fiber. It delegates to Symfony VarDumper's `dump()` when
     * available (require-dev), `var_dump()` otherwise: source location,
     * HTML/CLI rendering and server mode (Buggregator) are VarDumper's job.
     *
     * Route dumps to Buggregator with:
     * VAR_DUMPER_FORMAT=server
     * VAR_DUMPER_SERVER=buggregator:9912
     *
     * In production (APP_DEBUG disabled) it does nothing, use `dd()` to stop.
     * @param array<mixed> ...$vars
     */
    function d(...$vars): void
    {
        // Avoid running this in production apps
        $enabled = \Kaly\Util\Env::getBool(\Kaly\Core\App::ENV_DEBUG);
        if (!$enabled) {
            return;
        }

        $fn = function_exists('dump') ? 'dump' : 'var_dump';
        foreach ($vars as $v) {
            $fn($v);
        }
    }
}

if (!function_exists('dd')) {
    /**
     * Dump variables and stop the execution with a non-zero status.
     * @param array<mixed> ...$vars
     */
    function dd(...$vars): never
    {
        d(...$vars);
        exit(1);
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
