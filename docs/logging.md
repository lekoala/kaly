---
layout: default
title: Logging
nav_order: 21
---
# Logging

## Built in loggers

You can always get a `Psr\Log\LoggerInterface` from the Di container. It defaults to a `NullLogger` if none is defined.

In debug mode, there is a file based logger that will output in the `temp/logs/` runtime folder under the "debug.log" file. It is meant as a disposable debug helper: logs with rotation or retention belong to the application's own `LoggerInterface` binding, not to this fallback.
It is accessible under the `App::DEBUG_LOGGER` definition in the Di container. It is safe to keep code calling the Debug logger in prod
because it will be converted to a simple `NullLogger`. No worries!

When a module binds its own `LoggerInterface`, the debug logger follows it (same instance) instead of the file. When replacing the logger in `configure()`, also replace `App::DEBUG_LOGGER` to send debug messages to it. Use `rebind()`: `configure()` runs after the framework defaults are registered, so the id already exists:

```php
$app->configure(static function (Definitions $di) use ($logger): void {
    $di->rebind(App::DEBUG_LOGGER, $logger);
});
```

Debug mode does not automatically log the middleware pipeline on each request.
Executed middlewares are tracked in `HttpContext` in every mode and shown on the
debug error page. To log them on every request, register an `onTerminate()` hook:

```php
use Kaly\Core\App;
use Kaly\Core\HttpContext;
use Psr\Log\LoggerInterface;

/** @var LoggerInterface $logger */
$logger = $app->container()->get(App::DEBUG_LOGGER);
$app->onTerminate(static function (HttpContext $ctx) use ($logger): void {
    $logger->debug('pipeline status={status} executed={executed}', [
        'status' => $ctx->response()->getStatusCode(),
        'executed' => $ctx->middlewares(),
    ]);
});
```

## The logger class

Kaly provide a simple file based logger for basic needs. Please use a more suitable logger if you have more complex needs (example below).

## Errors

If you have a logger implementation, it will log by default application errors.

Two kinds of exceptions reach the kernel:

- an **HTTP exception** (`HttpExceptionInterface`: not found, redirect, validation,
  method not allowed...) is an expected outcome. It is never logged nor reported to
  `onError()`: a 404 must not wake up an error tracker;
- any **other exception** is an error: it is logged, reported to `onError()`, and
  becomes a `500`, regardless of its exception code.

The `ExceptionHandler` then builds the response in the format the client accepts:

| | HTML / text client | JSON client (`Accept: application/json`) |
| --- | --- | --- |
| production | the public body of the exception, or the status text (`Not Found`, `Server error`) | `application/problem+json` (RFC 9457): `type`, `title`, `status`, and `detail` when the exception has a public body |
| debug | the debug page | the same, plus the `detail` and an `exception` member with the chain and traces |

Nothing internal leaks in production: the message of a generic exception, or the
reason of a 404 (which names classes), is only shown in debug mode.

The **debug page** shows the exception chain, the code around each location with an
IDE link (`DUMP_IDE_PLACEHOLDER`, `vscode://file/{file}:{line}:0` by default), the
trace, and what the cycle had established: the route, the module, the locale, the
middlewares that ran. For a 404 it says why nothing matched, eg:

```text
Route '/shop/cart/add/' not found in module 'shop':
Kaly\Router\ConventionResolver: Param 'id' is required for action 'add' on 'Shop\Controller\CartController'
```

A custom resolver can give its own reason the same way, by throwing a
`RouteNotFoundException` instead of returning `null`.

Errors raised while configuring a module name it: a failing `config.php`
(`Module 'Shop' config.php failed: ...`) or an invalid route table
(`Invalid route table of module 'shop' (config.php): ...`), with the original
exception as previous.

`ErrorHandler` configures PHP itself: it always collects every error, converts them to
exceptions and never displays them publicly (`display_errors` is disabled outside of
debug mode). It also renders the errors that happen before a request cycle exists,
like a failing boot in `App::run()`.

Hooks are isolated by the kernel: a failing `onTerminate()` hook is reported as an
error and never masks a successful response, and a failing `onError()` hook never
prevents the error response (it is recorded on the context).

## Production setup

Bind a persistent logger so errors are actually recorded:

```php
use Kaly\Core\App;
use Kaly\Di\Definitions;
use Kaly\Log\FileLogger;
use Psr\Log\LoggerInterface;

$app = App::create(dirname(__DIR__));
$app->configure(function (Definitions $definitions) use ($app): void {
    $definitions->rebind(LoggerInterface::class, new FileLogger($app->paths()->base . '/app.log'));
});
```

### Sessions in a worker

Create one session per request through the request context (`$ctx->session()`).
Native PHP session state is global to the process: Kaly resets the native session
id on `start()` and `close()` so it cannot leak between sequential requests, but
two requests running concurrently in the same process (eg: coroutines) must not
share the native `$_SESSION`. For such runtimes, bind a `SessionProviderInterface`
returning request-scoped storage, and avoid the `NativePhpSession` storage backend.
See [Runtime](runtime.md).

## Example: configuring sentry

Having a simple integration of Sentry is really easy with Kaly. It basically boils down to this.

The `onError()` hook is the only hook point needed.

```php
use Kaly\Core\App;
use Kaly\Core\HttpContext;
use Throwable;

if (isset($_ENV['SENTRY_DSN'])) {
    \Sentry\init([
        'dsn' => $_ENV['SENTRY_DSN'],
        'environment' => $app->isDebug() ? 'dev' : 'prod'
    ]);
    $app->onError(function (Throwable $exception, HttpContext $ctx): void {
        \Sentry\captureException($exception);
    });
}
```

