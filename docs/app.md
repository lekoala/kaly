# App

> Application bootstrap and request kernel

## Usage

A kaly app is created from the entry file with the base directory that contains the
system folders (`modules/`, `public/`, `temp/`, `resources/`).

The app looks for a `.env` file in the base directory unless the `IGNORE_DOT_ENV`
environment variable is set.

```
APP_DEBUG=true
APP_TIMEZONE=UTC
```

## PSR-7 implementation

The core only depends on the PSR interfaces. You must provide a PSR-7
implementation and bind the PSR-17 factories your app needs (Nyholm is recommended):

```php
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

$definitions
    ->bind(RequestFactoryInterface::class, Psr17Factory::class)
    ->bind(ResponseFactoryInterface::class, Psr17Factory::class)
    ->bind(ServerRequestFactoryInterface::class, Psr17Factory::class)
    ->bind(StreamFactoryInterface::class, Psr17Factory::class)
    ->bind(UploadedFileFactoryInterface::class, Psr17Factory::class)
    ->bind(UriFactoryInterface::class, Psr17Factory::class);
```

## Index file

The entry file builds the request (Nyholm is recommended) and passes it to `run()`:

```php
<?php

use Kaly\Core\App;

require '../vendor/autoload.php';

$psr17Factory = new Nyholm\Psr7\Factory\Psr17Factory();
$creator = new Nyholm\Psr7Server\ServerRequestCreator(
    $psr17Factory, // ServerRequestFactory
    $psr17Factory, // UriFactory
    $psr17Factory, // UploadedFileFactory
    $psr17Factory, // StreamFactory
);

$app = new App(dirname(__DIR__));
$app->run($creator->fromGlobals());
```

`run()` boots the app if needed, handles the request and emits the response.

## Application and Kernel

Boot and request handling are split in two objects:

- `Kaly\Core\Application` — env, directories, modules, definitions, container,
  injector; `boot()` builds everything once.
- `Kaly\Core\Kernel` — a stateless PSR-15 `RequestHandlerInterface`. It creates the
  [HttpContext](http-context.md) of the cycle, runs the request callbacks, delegates
  to the pipeline and maps exceptions to responses.

`Kaly\Core\App` is a thin facade (`Application` + `Kernel`) and is what most apps use.

Because the kernel holds no per-request state, the same `Application` can handle many
requests, which makes worker setups (RoadRunner, Swoole, FrankenPHP...) straightforward.
Everything that belongs to a single cycle lives in its context instead:

```text
one request -> one context -> the whole cycle -> one response
```

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

$app = new Kaly\Core\App(dirname(__DIR__));
$app->boot();

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

- configure error handling and, in debug mode, ensure the system directories exist;
- discover the modules and load their config files;
- build the definitions, the DI container and the injector;
- build the request kernel.

You can start adding middlewares after `boot()`.

## Callbacks

Callbacks are a simple alternative to event dispatchers. Valid ids are exposed as
`App::CB_*` constants:

- `App::CB_BOOTED`
- `App::CB_BEFORE_DEFINTITIONS`
- `App::CB_AFTER_DEFINITIONS`
- `App::CB_BEFORE_REQUEST`
- `App::CB_AFTER_REQUEST`
- `App::CB_ERROR` (generic errors only; HTTP exceptions are expected and skipped)

The request callbacks receive the [HttpContext](http-context.md) of the cycle:

```text
CB_BEFORE_REQUEST(HttpContext $ctx)
CB_AFTER_REQUEST(HttpContext $ctx)
CB_ERROR(Throwable $e, HttpContext $ctx)
```

```php
$app->addCallback(App::CB_ERROR, function (Throwable $e, HttpContext $ctx): void {
    // report to your error tracker
    myTracker()->report($e, [
        'route' => $ctx->hasRoute() ? $ctx->route()->controller : null,
        'middlewares' => $ctx->middlewares(),
    ]);
});
```

## Using middlewares

Middlewares are resolved from the container and are not a free list: kaly has a fixed
request flow with a routing step in the middle, and a middleware is registered in one
of the phases around it.

```text
incoming -> routing -> routed -> dispatcher -> (kernel) -> outgoing
```

- **incoming** runs before anything is routed: trusted proxies, request id, static
  files, global rate limits...
- **routing** is a structural step of the framework, not a configurable middleware. It
  matches the route and resolves the locale.
- **routed** runs with a route already known: auth, authorization, CSRF, per route
  rate limits...
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
    ->outgoing(WebpResponse::class);
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
| response finalization | the response that really leaves the application |

The **outgoing middleware band** is the response phase with a real contract: it runs
exactly once, after the kernel produced a response — from the happy path, a
short-circuited request or an exception. It executes a `Response -> Response`
transformation, and if it throws the kernel turns the exception into a new error
response:

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

`finalizeResponse` is still the best-effort finishing step: a narrow transformation
in the kernel, after the outgoing band, that runs on every response that leaves the
application — on the happy path as well as on kernel-built error responses:

```php
$app->addCallback(App::CB_FINALIZE_RESPONSE,
    static fn(ResponseInterface $response, HttpContext $ctx): ResponseInterface
        => $response->withHeader('X-Request-Id', $ctx->request()->getHeaderLine('X-Request-Id')));
```

The two do not overlap:

- an **outgoing** middleware is part of producing the correct result. If it fails,
  the request fails — the exception becomes an error response;
- a **finalizeResponse** callback is an enhancement. If it fails, the previous
  response is kept and the error is reported (never-mask).

Guards: a throwing finalizer never masks the response (the previous response is
kept and the error is reported), and a non-response return is treated the same
way.

`CB_AFTER_REQUEST` is not that hook either: it runs once the context is already
complete, and it is a notification whose errors must not change the response.

## Env variables

Any env variable can be defined in the application server, otherwise an `.env` file in
the base directory is loaded (`parse_ini_file` format). Set `IGNORE_DOT_ENV` to skip the
filesystem lookup.

The loader is strict: keys must be valid environment variable names
(`^[A-Za-z_][A-Za-z0-9_]*$`) and each value must be a string. The file is read in raw
mode, so INI specific conversions and interpolation do not apply: use the typed
`Env::getBool()` / `getInt()` / `getFloat()` / `getArray()` accessors to interpret values.

`APP_DEBUG` toggles debug mode (error reporting, debug logger, directory setup).

## Modules

All folders in the modules dir with a `config.php` are modules. Config files are
executed during bootstrap and provide definitions for the DI container. They are
discovered in a deterministic order (sorted by folder name) and executed by priority
(lower first); an explicit priority set by the module always wins.

Registration is eager, resolution is lazy, request behaviour is route aware. See
[Modules](modules.md).

## The DI container

All definitions provided by the module configs are merged, then the app defaults are
registered if not already defined, and the definitions are locked.

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
