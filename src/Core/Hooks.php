<?php

declare(strict_types=1);

namespace Kaly\Core;

use Closure;
use Kaly\Di\Definitions;
use Kaly\Http\HttpExceptionInterface;
use Throwable;

/**
 * The typed extension points of the application lifecycle.
 *
 * Everything that happens around a request is a middleware (incoming, routed,
 * outgoing). Hooks only cover what a middleware cannot see: composing the
 * container, the end of the boot, reporting errors, and the end of a cycle.
 *
 * ```text
 * configure -> boot                               (once)
 * request -> ... -> response -> terminate         (every cycle)
 *                 \-> error (generic exceptions)
 * ```
 *
 * A failing hook never masks the cycle: error hook failures are recorded on
 * the context, terminate failures are reported as errors.
 *
 * @internal Registered through App::configure(), onBoot(), onError(), onTerminate()
 */
final class Hooks
{
    /**
     * @var list<Closure(Definitions): void>
     */
    public array $configure = [];

    /**
     * @var list<Closure(App): void>
     */
    public array $boot = [];

    /**
     * @var list<Closure(Throwable, HttpContext): void>
     */
    public array $error = [];

    /**
     * @var list<Closure(HttpContext): void>
     */
    public array $terminate = [];

    public function configure(Definitions $definitions): void
    {
        foreach ($this->configure as $hook) {
            $hook($definitions);
        }
    }

    public function boot(App $app): void
    {
        foreach ($this->boot as $hook) {
            $hook($app);
        }
    }

    /**
     * Report a generic error. HTTP exceptions are expected outcomes, not
     * errors, and are never reported.
     */
    public function error(Throwable $ex, HttpContext $ctx): void
    {
        if ($ex instanceof HttpExceptionInterface) {
            return;
        }
        foreach ($this->error as $hook) {
            try {
                $hook($ex, $ctx);
            } catch (Throwable $hookError) {
                // A broken error hook must not prevent the error response
                $ctx->addCallbackError($hookError);
            }
        }
    }

    public function terminate(HttpContext $ctx): void
    {
        foreach ($this->terminate as $hook) {
            try {
                $hook($ctx);
            } catch (Throwable $ex) {
                $this->error($ex, $ctx);
            }
        }
    }
}
