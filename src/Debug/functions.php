<?php

declare(strict_types=1);

// Optional debugging helpers, explicitly opt-in: Kaly never loads this file
// and never declares global functions on its own. Require it from your entry
// point if you want `d()`/`dd()`:
//
// require __DIR__ . '/../vendor/lekoala/kaly/src/Debug/functions.php';
//
// A name collision with another `d()`/`dd()` fails loudly on purpose: the
// conflict is real, and hiding it behind declaration order would only move
// the surprise somewhere else.
//
// Nothing here reaches into the application: there is no global container in
// kaly. To translate, use the $i18n variable of your views or inject a
// TranslatorInterface. To log, inject a LoggerInterface. To read
// configuration, use Kaly\Util\Env.

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

/**
 * Dump variables and stop the execution with a non-zero status.
 * @param array<mixed> ...$vars
 */
function dd(...$vars): never
{
    d(...$vars);
    exit(1);
}
