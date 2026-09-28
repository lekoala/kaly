# App

> Application lifecycle and request kernel

## Usage

A kaly app is created from the entry file with the base directory that contains the
conventional folders (`modules/`, `public/`, `temp/`, `resources/`, see `Kaly\Core\Paths`):

```php
<?php
// public/index.php
require __DIR__ . '/../vendor/autoload.php';

Kaly\Core\App::create(dirname(__DIR__))->run();
```

`run()` boots the app, builds the request from the PHP globals, handles it and emits the
response. A failure during boot still produces a proper 500 (with details in debug mode).

The app looks for a `.env` file in the base directory unless the `IGNORE_DOT_ENV`
environment variable is set.

```
APP_DEBUG=true
APP_TIMEZONE=UTC
```

## PSR-7 implementation

The core only depends on the PSR interfaces: `composer require` a PSR-7 implementation
and Kaly binds its PSR-17 factories. Nyholm is recommended; Guzzle, Laminas Diactoros and
HttpSoft are discovered as well. An explicit binding always wins over discovery.

```bash
composer require nyholm/psr7
```

## App and Kernel

Boot and request handling are split in two objects:

- `Kaly\Core\App` — env, paths, modules, definitions, container, injector, hooks and the
  middleware configuration; `boot()` builds everything once. `handle()` and `run()` boot
  the app when needed.
- `Kaly\Core\Kernel` — a stateless PSR-15 `RequestHandlerInterface`. It creates the
  [HttpContext](http-context.md) of the cycle, delegates to the pipeline, commits the
  session and cookies, runs the outgoing phase and maps exceptions to responses.

Because the kernel holds no per-request state, the same app can handle many requests,
which makes worker setups (RoadRunner, Swoole, FrankenPHP...) straightforward.
Everything that belongs to a single cycle lives in its context instead:

```text
one request -> one context -> the whole cycle -> one response
```

See [Runtime](runtime.md) for the rules this relies on: Kaly is an HTTP
framework, not an execution runtime.

## Using Road Runner

Since the boot process happens only once, you get a really minimal overhead per request.

```php
<?php

use Spiral\RoadRunner;
use Nyholm\Psr7;

require "vendor/autoload.php";

$worker = RoadRunner\Worker::create();
$psrFactory = new Psr7\Factory\Psr17Factory();

$worker = new RoadRunner\Http\PSR7Worker($worker, $psrFactory, $psrFactory, $psrFactory);

$app = Kaly\Core\App::create(dirname(__DIR__))->boot();

while ($req = $worker->waitRequest()) {
    try {
        $response = $app->handle($req);
        $worker->respond($response);
    } catch (\Throwable $e) {
        $worker->getWorker()->error((string) $e);
    }
}
```

## Bootstrap

`boot()` will:

- configure error handling and, in debug mode, ensure the conventional directories exist;
- discover the modules and run their `config.php` and `routes.php`;
- build the definitions (modules, then `configure()` hooks, then framework defaults),
  the DI container and the injector;
- mount every module on the convention router;
- build the request kernel.

The container is locked once booted: `configure()`, `onBoot()` and `debug()` must be
called before. Middlewares and the request hooks can still be added afterwards.

## Hooks

Everything that happens around a request is a middleware. Hooks only cover what a
middleware cannot see, and they are typed methods, not string ids:

| Hook | Receives | Runs |
| --- | --- | --- |
| `configure()` | `Definitions` | once, after the modules, before the framework defaults |
| `onBoot()` | `App` | once, when the app is booted |
| `onError()` | `Throwable`, `HttpContext` | on generic errors (HTTP exceptions are expected and skipped) |
| `onTerminate()` | `HttpContext` | at the end of every cycle, `$ctx->response()` is available |

```php
$app = App::create(dirname(__DIR__))
    ->configure(fn(Definitions $di) => $di->set(LoggerInterface::class, new FileLogger('app.log')))
    ->onError(function (Throwable $e, HttpContext $ctx): void {
        // report to your error tracker
        myTracker()->report($e, [
            'route' => $ctx->hasRoute() ? $ctx->route()->controller : null,
            'middlewares' => $ctx->middlewares(),
        ]);
    });
```

A failing hook never masks the cycle: a broken error hook is recorded on the context
(`$ctx->callbackErrors()`), a broken terminate hook is reported as an error.

## Using middlewares

Middlewares are resolved from the container and are not a free list: kaly has a fixed
request flow with a routing step in the middle, and a middleware is registered in one
of the phases around it. A middleware resolved from a class string is an
application-scoped service: the same instance may handle sequential or concurrent
requests, so it must be safe to re-enter. Keep request-specific state in local
variables, the PSR-7 messages or the [HttpContext](http-context.md), never in a
middleware property.

```text
incoming -> routing -> routed -> route middlewares -> dispatcher -> (kernel) -> outgoing
```

- **incoming** runs before anything is routed: trusted proxies, request id, static
  files, global rate limits...
- **routing** is a structural step of the framework, not a configurable middleware. It
  matches the route and resolves the locale.
- **routed** runs with a route already known: auth, authorization, CSRF, per route
  rate limits...
- **route middlewares** are the ones declared on a route, a group or a controller
  (`#[Middleware]`), see [Explicit routes](explicit-routes.md).
- **outgoing** runs *on the response*, once the whole cycle produced one, whatever its
  origin (happy path, short-circuit, kernel-built error): webp conversion, compression,
  cache headers...

```php
$app = new App(dirname(__DIR__));
// Example middleware names — ship your own PSR-15 implementations,
// Kaly only bundles FileServer and PreventFileAccess.
$app->middleware()
    ->incoming(TrustedProxy::class)
    ->incoming(RequestId::class)
    ->routed(AuthMiddleware::class, priority: 100)
    ->routed(RateLimitMiddleware::class, priority: 200)
    ->outgoing(WebpResponse::class)
    ->outgoing(SecurityHeaders::class, always: true);
```

Ordering is deliberately simple: the phase order is fixed, and inside a phase
middlewares run by ascending priority, then by registration order. There is no
`before()` / `after()` / `requires()` dependency graph to reason about — if a
middleware needs the route, it belongs in the routed band.

Conditions are expressed on the [HttpContext](http-context.md), so a routed condition
can read state that has already been established:

```php
$app->middleware()->routed(
    AdminAuth::class,
    when: static fn(HttpContext $ctx): bool => $ctx->route()->module === 'Admin',
);
```

An outgoing condition receives the **current response** instead of a request, so it
can decide on the response produced so far — including the one produced by an earlier
outgoing middleware:

```php
$app->middleware()->outgoing(
    WebpResponse::class,
    when: static fn(ResponseInterface $response): bool => $response->getStatusCode() === 200,
);
```

Returning `false` skips the middleware for that request. Request conditions are
evaluated on every request, so they can also depend on external state.

### Three ways to write one

These are not three competing APIs, they are one progression. Start at the top and go
down only when the problem asks for it.

| | Use it for |
| --- | --- |
| PSR-15 `MiddlewareInterface` | third party middlewares, interop, the classic nested model |
| `GeneratorMiddleware` | the native default: simple `before()` / `after()` hooks |
| `GeneratorMiddlewareInterface` | the native advanced form: local state across both phases, `catch` / `finally` |

All three are registered the same way and run in the same bands.

### Before and after hooks

For middlewares that need both a "before" and an "after" phase, extend
`Kaly\Middleware\GeneratorMiddleware` and implement the hooks. This covers most needs:

```php
final class Timing extends GeneratorMiddleware
{
    public function before(ServerRequestInterface $request): ServerRequestInterface|ResponseInterface
    {
        return $request->withAttribute('start', hrtime(true));
    }

    public function after(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $ms = (hrtime(true) - $request->getAttribute('start')) / 1e6;
        return $response->withHeader('X-Duration', (string) round($ms, 2));
    }
}
```

- `after()` receives the request **its own `before()` returned**, so anything set in
  `before()` is there — as in the example above.
- `before()` may return a **response** instead of a request. The inner layers never
  run and this middleware's own `after()` is skipped, since it already owns the
  response. Outer middlewares still wrap it. This is what an auth or a cache
  middleware needs.

### Keeping state across both phases

When a middleware needs to hold something between its two phases — a timer, a
transaction, an open resource — implement `GeneratorMiddlewareInterface` directly. The
local variables of the method survive the suspension, so there is no per-request state
to store anywhere else:

```php
final class Timing implements GeneratorMiddlewareInterface
{
    public function process(ServerRequestInterface $request): Generator
    {
        $start = hrtime(true);
        try {
            $response = yield $request;
            return $response->withHeader('Server-Timing', $this->format($start));
        } finally {
            $this->record(hrtime(true) - $start);
        }
    }
}
```

This is the one thing separate `before()` / `after()` hooks cannot express without
inventing a parallel request-scoped store — which is exactly why the generator form is
kept.

### Handling downstream errors

An exception thrown further down the stack is rethrown **at the yield point**, so a
middleware can catch it, or clean up in a `finally`:

```php
final class Transaction implements GeneratorMiddlewareInterface
{
    public function process(ServerRequestInterface $request): Generator
    {
        $this->db->begin();
        try {
            $response = yield $request;
            $this->db->commit();
            return $response;
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}
```

An exception nobody catches simply keeps going up, and the kernel turns it into a
response.

The generator protocol is deliberately narrow — it is a middleware mechanism, not a
general coroutine — and a violation is reported as such rather than as a cryptic
generator error:

- yield the request **at most once** — zero to short-circuit;
- yield a `ServerRequestInterface`, never anything else;
- **return** a `ResponseInterface`.

### What an after phase is not

An error response built by the kernel has **not** gone back through the `after()`
phases. This is deliberate: when the stack fails halfway, some middlewares were
entered and some were not, so replaying their `after()` would give a result nobody can
predict. Four distinct things are easy to confuse:

| | Runs on |
| --- | --- |
| `after()` | a response the inner layers actually returned |
| `finally` | every outcome, including an exception — for cleanup |
| `outgoing` | the response produced by the whole cycle, whatever its origin |
| `outgoing(..., always: true)` | every response that really leaves the application |

The **outgoing middleware band** is the response phase with a real contract: the phase
is attempted once for each response produced by the request cycle — from the happy
path, a short-circuited request or an exception. It executes a `Response -> Response`
transformation. If an outgoing middleware throws, the phase stops: the exception is
converted to a new error response and the transformations applied earlier in the band
are discarded. Headers that must survive an outgoing failure belong in an
`always` outgoing middleware.

```php
$app->middleware()->outgoing(WebpResponse::class);

// WebpResponse implements Kaly\Middleware\OutgoingMiddlewareInterface:
final class WebpResponse implements OutgoingMiddlewareInterface
{
    public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
    {
        return $response->withHeader('X-Format', 'webp');
    }
}
```

An outgoing middleware also runs on responses with no route at all (an incoming
short-circuit, a routing 404) — guard `route()` and `locale()`:

```php
final class RouteHeader implements OutgoingMiddlewareInterface
{
    public function process(ResponseInterface $response, HttpContext $ctx): ResponseInterface
    {
        return $ctx->hasRoute()
            ? $response->withHeader('X-Route', $ctx->route()->controller)
            : $response;
    }
}
```

An outgoing middleware marked `always` is a **guarantee** rather than a step. It runs
on every response that leaves the application, including the error response that
replaces a failed outgoing phase, and it can be a plain closure:

```php
$app->middleware()->outgoing(
    static fn(ResponseInterface $response, HttpContext $ctx): ResponseInterface
        => $response->withHeader('X-Request-Id', $ctx->request()->getHeaderLine('X-Request-Id')),
    always: true,
);
```

The two do not overlap:

- a regular **outgoing** middleware is part of producing the correct result. If it
  fails, the request fails — the exception becomes an error response;
- an **always** outgoing middleware is an enhancement. If it fails, the response it
  received goes on unchanged and the error is reported (never-mask). A closure that
  does not return a response is treated the same way.

`onTerminate()` is not that hook either: it runs once the context is complete, and it
is a notification whose errors must not change the response.

## Env variables

Any env variable can be defined in the application server, otherwise an `.env` file in
the base directory is loaded (`parse_ini_file` format). Set `IGNORE_DOT_ENV` to skip the
filesystem lookup.

`Env` reads `$_ENV` with a read-only `getenv()` fallback, so real environment variables
are visible even when `variables_order` does not contain `E`. `$_SERVER` is deliberately
not consulted. Kaly never calls `putenv()`: values written by `Env::set()` or `Env::load()`
live in `$_ENV` (the `Env` view), while `getenv()` called directly still sees the original
process value.

Precedence is: process environment first, `.env` only fills the gaps. `Env::load()` skips
keys that are already defined unless `$overwrite` is `true` (which then only replaces what
Kaly sees via `Env`). An explicit `null` in `$_ENV` counts as defined.

The loader is strict: keys must be valid environment variable names
(`^[A-Za-z_][A-Za-z0-9_]*$`) and each value must be a string. The file is read in raw
mode, so INI specific conversions and interpolation do not apply: use the typed
`Env::getBool()` / `getInt()` / `getFloat()` / `getArray()` accessors to interpret values.

The `.env` syntax follows `parse_ini_file` in raw mode, with a few consequences worth
knowing:

- Comments: `;` starts a comment, either on its own line or at the end of a value.
  `#` only starts a comment at the beginning of a line; an inline `#` is part of the
  value (secrets containing `#` are therefore kept intact, but `"value" # note` yields
  the literal `"value" # note`).
- Quotes: only double quotes are interpreted and stripped. Single quotes are kept
  literally, and a `;` inside them still starts a comment. Prefer double quotes.
- Shell syntax is not supported: `export KEY=value` is rejected as an invalid
  environment variable name.
- Empty vs absent: `Env::get()` returns its default (`null`) for an empty value, so an
  empty entry and a missing one are indistinguishable through it. Use
  `Env::getString()` (returns `''`) or `Env::has()` to tell them apart.

`APP_DEBUG` toggles debug mode (error reporting, debug logger, directory setup).

`APP_TIMEZONE` sets the global PHP timezone at boot. When it is absent, Kaly leaves the
global timezone alone (`php.ini` or a prior `date_default_timezone_set()` survives) — no
`UTC` is imposed. It does not reconfigure the injected `SystemClock`, which stays UTC by
default (see `SystemClock::fromSystemTimezone()` for a system-timezone clock).

## Modules

All folders in the modules dir with a `config.php` are modules. Config files return a
closure run during bootstrap, which configures the module and provides definitions for
the DI container. They are discovered in a deterministic order (sorted by folder name)
and configured by priority (lower first); an explicit priority set by the module always
wins. Every module is routable by convention under its name.

Registration is eager, resolution is lazy, request behaviour is route aware. See
[Modules](modules.md).

## The DI container

All definitions provided by the module configs are merged, then the `configure()` hooks
run, then the app defaults (PSR-17 factories, router, logger...) are registered if not
already defined, and the definitions are locked.

The container is instantiated ONCE during boot. Subsequent requests on the same app
instance reuse it.

## Routing

The routing is done by a class implementing the `RouterInterface`. See the
`ClassRouter` docs for more information.

A controller must return one of:

- a `ResponseInterface` (used as-is)
- a `Kaly\View\View` (rendered to HTML by the configured renderer)
- an `array` (JSON response)
- a `string` (HTML response)
- `null` (empty response)
