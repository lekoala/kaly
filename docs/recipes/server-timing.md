---
layout: default
title: Server-Timing recipe
nav_order: 7
---
# Server-Timing recipe

Kaly can measure its own request stages without instrumenting controllers or
templates. Collection is opt-in and independent of debug mode or HTTP export:

```php
use Kaly\Core\App;

$app = App::default(dirname(__DIR__));
$app->profiling(enabled: $app->isDebug(), serverTiming: $app->isDebug());
```

The framework measures the stages itself. With `serverTiming: true`, the kernel
exports the completed response-production profile after outgoing middleware and
commit, including error responses and incoming short-circuits. Profiling alone
adds no response header; disabling profiling also disables HTTP export.

## What is measured

Each profiled request owns a `Kaly\Debug\Profile` on its `HttpContext`.
The framework records elapsed durations with `hrtime(true)`, a monotonic timer,
independently of the calendar clock used for
[application behavior](../application-structure.md#time-dependencies).

| Metric | Boundary |
| --- | --- |
| `routing` | Route matching and locale resolution, before calling downstream middleware |
| `controller` | Controller construction, input mapping, action invocation and result validation |
| `serialization` | JSON encoding of array and `JsonResult` results, including `jsonSerialize()` calls |
| `view` | View preparation, template rendering and response construction in `ViewResponder` |
| `commit` | Session persistence and cookie headers, including a failed commit attempt |
| `request` | Response production, outgoing transformations, error recovery and commit |

`request` ends before terminate hooks, final session cleanup and response emission.
It includes the other stages and time in request middleware. `controller` and
`view` are separate when an action returns a `View`; rendering performed inside
the action is included in `controller`. Durations are not an additive breakdown.
Stages that never run are absent. Failed stages are recorded in `finally`.

The boot profile belongs to the application instance and is read separately:

```php
$app->boot();
$boot = $app->bootProfile()?->metrics();
// ['boot' => ['duration' => 12.4, 'count' => 1]] (illustrative)
```

Boot runs once per instance, including in workers. Its duration covers
`App::boot()`, including boot hooks, and remains available if boot fails.
It excludes constructing the App and loading the entry point or autoloader.
It is never copied into request profiles or HTTP headers.

## Inspect and log

The [Server-Timing header](https://www.w3.org/TR/server-timing/) exposes durations
in milliseconds, for example:

```http
Server-Timing: routing;dur=2.1, controller;dur=51.6, view;dur=14.8, commit;dur=0.2, request;dur=69.4
```

In Chrome DevTools, select the request under **Network → Timing**. On the same
origin, JavaScript can read `performance.getEntriesByType('navigation')[0]?.serverTiming`.
Repeat comparable requests and compare medians, separating cold starts and cache hits.

The header includes `commit` and the finished `request` duration. Export runs
once on the final response, preserving any existing Server-Timing values.
Outgoing middleware still runs before commit; HTTP export is a separate final
step. `request` excludes the export itself. Read the same completed
response-production profile in a terminate hook for internal logs:

```php
use Kaly\Core\HttpContext;
$app->onTerminate(static function (HttpContext $ctx) use ($logger): void {
    $logger->info('Request profile', [
        'request_id' => $ctx->requestId(),
        'status' => $ctx->response()->getStatusCode(),
        'metrics' => $ctx->profile()?->metrics(),
    ]);
});
```

Here `$logger` is your application's PSR-3 logger (see [logging](../logging.md)).
`metrics()` returns milliseconds and invocation counts. Repeated measurements
of a name accumulate. Application adapters can record explicit operations with
`$ctx->profile()?->record('db', $elapsedNanoseconds)`; metric names must match
`[a-z][a-z0-9_-]*`. Names are limited to 64 characters and each profile keeps
at most 64 distinct metrics, in order of first recording. Longer names and new
names beyond this limit are silently ignored; existing names still accumulate.
These limits also apply to native stages: a full profile can omit later stages.
Invalid tokens or durations still raise `Kaly\Ex`.
There is no automatic SQL instrumentation or pure-template
metric. Profiles are local to each request, including interleaved Fibers.
Elapsed time includes waits and Fiber suspension; it is not CPU time.

## Production and caches

In development, enable collection and HTTP export. In production, collection
can remain enabled for internal logs with `$app->profiling()`, while HTTP export
stays disabled.

`App::profiling(serverTiming: true)` exports every collected metric on every
response, including application metrics added with `record()`. It has no
per-request authorization or filtering and is not sufficient for diagnostics
restricted to administrators. Production HTTP diagnostics require a separate
per-request authorization mechanism before export is enabled, with private
responses excluded from shared caches. Avoid SQL
text, service names, cache keys and authentication details in exported metrics.
`Timing-Allow-Origin` controls cross-origin performance API access; it does not
hide the HTTP header. See the
[browser reference](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Server-Timing).

A CDN or HTML cache may replay timings from an earlier response generation.
Keep authorized diagnostics out of shared caches and interpret cached timings
accordingly. Use a full profiler for method-level costs, allocations and call
graphs, and internal observability for ongoing latency percentiles and error rates.
