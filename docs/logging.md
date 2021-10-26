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

## Example: configuring sentry

Having a simple integration of Sentry is really easy with Kaly. It basically boils down to this.

We use our callback feature to easily define hook points for sentry.

```php
if (isset($_ENV['SENTRY_DSN'])) {
    \Sentry\init([
        'dsn' => $_ENV['SENTRY_DSN'],
        'environment' => $this->getDebug() ? 'dev' : 'prod'
    ]);
    $this->addCallback(\Kaly\Auth::class, \Kaly\Auth::CALLBACK_SUCCESS, function (ServerRequestInterface $request) {
        \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($request) {
            $scope->setUser(['id' => $request->getAttribute(\Kaly\Auth::USER_ID_ATTR)]);
        });
    });
    $this->addCallback(\Kaly\Auth::class, \Kaly\Auth::CALLBACK_CLEARED, function (ServerRequestInterface $request) {
        \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($request) {
            $scope->removeUser();
        });
    });
    $this->addCallback(\Kaly\App::class, \Kaly\App::CALLBACK_ERROR, function (Throwable $exception) {
        \Sentry\captureException($exception);
    });
}
```
