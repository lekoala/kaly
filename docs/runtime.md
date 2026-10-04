---
layout: default
title: Runtime
nav_order: 5
---
# Runtime independence

Kaly is an HTTP framework, not an execution runtime.

The same booted application may be hosted by:

- a traditional request-per-process SAPI (PHP-FPM, PHP built-in server);
- a sequential long-lived worker (FrankenPHP, RoadRunner);
- a runtime executing several requests concurrently (Fibers, event loop).

Kaly therefore follows these rules:

1. Kernel and application services must not store request state.
2. Request state belongs to [HttpContext](http-context.md) or objects created for that request.
3. The application container is application-scoped: what it shares must be safe to share.
4. Kaly does not own an event loop, Fiber scheduler or async API.
5. Blocking vs suspendable I/O is a property of application/runtime adapters, not of the Kaly kernel.
6. Native PHP process-global facilities may have stricter runtime limitations — see below.
7. Kaly never requires a cache, and never writes one that changes the behavior of the
   application — see below.

## Consequences

Middleware objects resolved from the container are application-scoped. They
may be reused by sequential or concurrent requests and must be safe to
re-enter: request-specific state belongs in local variables, the PSR-7
messages or `HttpContext` — never in a middleware property. Shared mutable
state is application state and is legitimate (a metrics counter, a shared
rate limiter); per-request state in a property (a current user) is a bug.

`WorkerTest` locks the sequential contract (one cycle finishes, the next
starts clean); `ConcurrentRequestTest` locks the concurrent one (two
interleaved cycles on one booted app, each keeps its route, locale, cookies,
session, middleware trace and response — through the same shared middleware
instance).

Each request band snapshots its ordered middleware entries when entered. A
registry change affects later band entries, including later requests, but cannot
change the remaining steps of a band already running or suspended in a Fiber.
Configure middleware before serving requests so the whole application pipeline
stays consistent across cycles. Conditions still evaluate against the current
request context at each step.

## No cache, a worker instead

A persistent cache of routes, modules or definitions is a second source of truth:
it must be invalidated, and a stale one means a route that silently stops working.
Kaly does not have one. What it keeps is **memoization in memory**: route tables are
compiled on their first use, controller reflection is kept per class. It dies with the
process, and the code cannot change under a running process, so there is nothing to
invalidate.

The real optimization is to boot once. Measured with `composer bench` (see
[Benchmarks](benchmarks.md)):

| | per request |
| --- | --- |
| PHP-FPM, one process per request | autoload ~3 ms + boot ~7 ms + request ~3 ms |
| worker, after the first request | ~0.02 to 0.1 ms |

Boot does not grow with the size of the application: a url only compiles the
routes of the module it reaches.

## Worker mode

A booted app handles any number of requests. Nothing but the request changes between
two cycles, and a failing cycle does not affect the next one.

**FrankenPHP** refreshes the superglobals for each request, so `run()` works as is:

```php
<?php
// public/index.php
require __DIR__ . '/../vendor/autoload.php';

$app = Kaly\Core\App::create(dirname(__DIR__))->boot();

$handler = static fn() => $app->run();
while (frankenphp_handle_request($handler)) {
    gc_collect_cycles();
}
```

**RoadRunner** hands PSR-7 requests: use `handle()` and give the response back.

```php
<?php
use Spiral\RoadRunner;

require __DIR__ . '/../vendor/autoload.php';

$factory = new Nyholm\Psr7\Factory\Psr17Factory();
$worker = new RoadRunner\Http\PSR7Worker(RoadRunner\Worker::create(), $factory, $factory, $factory);

$app = Kaly\Core\App::create(dirname(__DIR__))->boot();

while ($request = $worker->waitRequest()) {
    try {
        $worker->respond($app->handle($request));
    } catch (Throwable $e) {
        $worker->getWorker()->error((string) $e);
    }
}
```

`SapiWorkerTest` simulates the FrankenPHP loop (superglobals refreshed, `run()` called
again and again) and `WorkerTest` the PSR-7 one.

## Cookies

Cookies are the model every request-scoped state should follow:

> Kaly never emits cookies through PHP globals. Cookies are read from the
> PSR-7 request and emitted as `Set-Cookie` headers on the PSR-7 response.

`Cookies` holds no shared state, so concurrent cycles are isolated by
construction. The application baseline (path, domain, secure, httponly,
samesite...) lives in the `CookiePolicy` service bound per `App` (immutable,
never a process-global default), shared by `Cookies` and the session cookie;
cookie *values* stay request-scoped. `SetCookieHeader` is the stateless
primitive underneath: name + value + params in, header string out.

## Native PHP sessions

`NativePhpSession` wraps the process-global `$_SESSION`. It is safe for
sequential execution — Kaly resets the native session id when it starts the
session (`startSession()`) and when it closes it (`close()`) so nothing leaks
between requests — but two requests running concurrently in the same process
must not share it.

```text
NativePhpSessionProvider (App's default)
    sequential worker    ✓
    concurrent Fibers    ✗

a provider returning request-scoped storage
    sequential worker    ✓
    concurrent Fibers    ✓
```

Concurrent runtimes bind a `SessionProviderInterface` returning request-scoped
storage. The provider owns transport (session id lookup, `Set-Cookie`
emission) while `SessionInterface` stays a backend-agnostic applicative
contract (`get/set/has/remove/clear/pull/all` + `regenerateId/destroy`).
Cookie- or server-backed session policies are a decision of the
application or of a dedicated package, not of the Kaly core.

`ArraySession` is concurrency-safe but persists nothing between requests: tests
and isolated cycles only, never a production backend.

