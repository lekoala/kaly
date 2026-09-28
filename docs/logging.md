# Logging

## Built in loggers

You can always get a `Psr\Log\LoggerInterface` from the Di container. It defaults to a `NullLogger` by default if none is defined.

In debug mode, there is a file based logger that will output in your base dir under the "debug.log" file. 
It is accessible under the `App::DEBUG_LOGGER` definition in the Di container. It is safe to keep code calling the Debug logger in prod
because it will be converted to a simple `NullLogger`. No worries!

Also, please note that you need to choose to output to the dev logger if you want to use it.

## The logger class

Kaly provide a simple file based logger for basic needs. Please use a more suitable logger if you have more complex needs (example below).

## Errors

If you have a logger implementation, it will log by default application errors.

In production, `ErrorHandler` always collects every error but never displays it
publicly: `display_errors` is disabled and each non-HTTP error is logged and
converted to a `500` response. Only when `APP_DEBUG` is enabled does the error
response include the message and trace (properly escaped).

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
    $definitions->set(LoggerInterface::class, new FileLogger($app->paths()->base . '/app.log'));
});
```

### Sessions in a worker

Create one session per request, either directly (`new Session()`) or through
the request context (`$ctx->session()`). Native PHP session state is global to
the process: Kaly resets the native session id on `start()` and `close()` so
it cannot leak between sequential requests, but two requests running
concurrently in the same process (eg: coroutines) must not share the native
`$_SESSION`. For such runtimes, inject a request-scoped `SessionInterface`
implementation such as `ArraySession` (see `HttpContext::useSession()`), and
avoid the `NativePhpSession` storage backend. See [Runtime](runtime.md).

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
