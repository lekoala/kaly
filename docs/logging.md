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

`beforeRequest` and `afterRequest` callbacks are isolated by the kernel: an
exception thrown by one of them is reported and converted to a response instead
of escaping the kernel, and a failing `afterRequest` never masks a successful
response.

## Production setup

Bind a persistent logger so errors are actually recorded:

```php
use Kaly\Core\App;
use Kaly\Log\FileLogger;
use Psr\Log\LoggerInterface;

$app->addCallback(App::CB_AFTER_DEFINITIONS, function ($definitions) use ($app): void {
    $definitions->set(LoggerInterface::class, new FileLogger($app->getBaseDir() . '/app.log'));
});
```

### Sessions in a worker

Create one session per request, either directly (`new Session()`) or through
`$request->getSession()`. Native PHP session state is global to the process:
Kaly resets the native session id on `start()` and `close()` so it cannot leak
between sequential requests, but two requests running concurrently in the same
process (eg: coroutines) must not share the native `$_SESSION`. For such
runtimes, prefer request-scoped session data and avoid the native session
storage backend.

## Example: configuring sentry

Having a simple integration of Sentry is really easy with Kaly. It basically boils down to this.

We use our callback feature to easily define hook points for sentry.

```php
use Kaly\Core\App;
use Throwable;

if (isset($_ENV['SENTRY_DSN'])) {
    \Sentry\init([
        'dsn' => $_ENV['SENTRY_DSN'],
        'environment' => $app->getDebug() ? 'dev' : 'prod'
    ]);
    $app->addCallback(App::CB_ERROR, function (Throwable $exception) {
        \Sentry\captureException($exception);
    });
}
```
