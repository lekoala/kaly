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
        'route' => $ctx->route?->controller,
        'middlewares' => $ctx->middlewares(),
    ]);
});
```

## Using middlewares

Middlewares are plain PSR-15 `MiddlewareInterface` implementations, resolved from the
container. They are not a free list though: kaly has a fixed request flow with a
routing step in the middle, and a middleware is registered in one of the two bands
around it.

```text
incoming -> routing -> routed -> dispatcher
```

- **incoming** runs before anything is routed: trusted proxies, request id, static
  files, global rate limits...
- **routing** is a structural step of the framework, not a configurable middleware. It
  matches the route and resolves the locale.
- **routed** runs with a route already known: auth, authorization, CSRF, per route
  rate limits...

```php
$app = new App(dirname(__DIR__));
$app->middleware()
    ->incoming(TrustedProxy::class)
    ->incoming(RequestId::class)
    ->routed(AuthMiddleware::class, priority: 100)
    ->routed(RateLimitMiddleware::class, priority: 200);

$app->run($request);
```

Ordering is deliberately simple: the band order is fixed, and inside a band
middlewares run by ascending priority, then by registration order. There is no
`before()` / `after()` / `requires()` dependency graph to reason about — if a
middleware needs the route, it belongs in the routed band.

Conditions are expressed on the [HttpContext](http-context.md), so a routed condition
can read state that has already been established:

```php
$app->middleware()->routed(
    AdminAuth::class,
    when: static fn(HttpContext $ctx): bool => $ctx->route?->module === 'Admin',
);
```

Returning `false` skips the middleware for that request. Conditions are evaluated on
every request, so they can also depend on external state.

For middlewares that need both a "before" and an "after" phase, extend
`Kaly\Middleware\GeneratorMiddleware` and implement the `before()` / `after()` hooks.

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

In a kaly app, all folders in the modules dir with a `config.php` are considered
modules. Config files are executed during bootstrap and can return definitions for the
DI container.

Modules are discovered in a deterministic order (sorted by folder name) and executed by
priority (lower first). An explicit priority set by the module always wins; otherwise a
priority is assigned from the discovery order.

> You still need to autoload your modules yourself in composer.json.

> Convention: the modules folder should match their namespace.

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
