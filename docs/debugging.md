---
layout: default
title: Debugging
nav_order: 21
---
# Debugging

Buggregator is a good companion for local development: it aggregates dumps,
logs, errors, emails, outgoing HTTP and profiler data in one viewer.
No dedicated adapter is needed — everything goes through standard protocols,
so point the DSNs below at it and it just works. This recipe is a suggestion,
not a prescription: any tool speaking the same protocols fits the same way.

## Recipe

```env
# Dumps (Symfony VarDumper server mode, see below)
VAR_DUMPER_FORMAT=server
VAR_DUMPER_SERVER=buggregator:9912

# Logs (Monolog SocketHandler with a JSON formatter)
LOG_SOCKET_URL=buggregator:9913

# Errors (Sentry SDK, reported from onError, see logging.md)
SENTRY_DSN=http://sentry@buggregator:8000/1

# Email (SMTP catcher, see mailer.md)
MAILER_DSN=smtp://buggregator:1025

# Outgoing HTTP (proxy, works with any client honoring it)
HTTP_PROXY=http://buggregator:8080
HTTPS_PROXY=http://buggregator:8080

# Profiler (XHProf data, see below)
PROFILER_ENDPOINT=http://profiler@buggregator:8000
```

## Dumps

`d()` dumps through Symfony VarDumper's `dump()` (`var_dump()` fallback) and never stops, so it stays safe inside a worker or a Fiber. `dd()` dumps and exits. Both do nothing in production (`APP_DEBUG` disabled).

Kaly registers no global function automatically: require the helpers explicitly if you want them, typically in your entry point (a name collision with another `d()`/`dd()` fails loudly, by design):

```php
require __DIR__ . '/../vendor/lekoala/kaly/src/Debug/functions.php';

// or only for development
if ($_ENV['APP_DEBUG'] ?? false) {
    require dirname(__DIR__) . '/vendor/lekoala/kaly/src/Debug/functions.php';
}
```

With the recipe above, `dump()` output — including the source location — is sent to Buggregator over TCP instead of polluting the HTTP response.

## Logs

Bind a real PSR-3 logger (eg: Monolog with a socket handler to `LOG_SOCKET_URL`) to collect application errors and debug messages. Pipeline logging is opt-in through an `onTerminate()` hook. See [logging](logging.md).

## Open in your editor

Two independent settings, with different placeholder syntaxes:

```env
# Kaly debug page (exceptions): {file} and {line}
DUMP_IDE_PLACEHOLDER=vscode://file/{file}:{line}:0
# PhpStorm: phpstorm://open?file={file}&line={line}
```

```ini
; VarDumper dump() output: %f and %l
xdebug.file_link_format="vscode://file/%f:%l"
; PhpStorm: phpstorm://open?file=%f&line=%l
```

## Profiling

Buggregator renders XHProf data as flame graphs and call graphs. Kaly provides no profiler abstraction: wrap a cycle with `start()` / `end()` from any XHProf-based package in a plain PSR-15 middleware. Example with `spiral-packages/profiler` (requires the XHProf extension):

```bash
composer require --dev spiral-packages/profiler
```

```php
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SpiralPackages\Profiler\Profiler;
use SpiralPackages\Profiler\DriverFactory;
use SpiralPackages\Profiler\Storage\WebStorage;
use Symfony\Component\HttpClient\NativeHttpClient;

final class ProfilerMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly Profiler $profiler,
    ) {}

    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler,
    ): ResponseInterface {
        $this->profiler->start();
        try {
            return $handler->handle($request);
        } finally {
            $this->profiler->end();
        }
    }
}

$storage = new WebStorage(new NativeHttpClient(), $_ENV['PROFILER_ENDPOINT'] . '/api/profiler/store');

$definitions->set(
    Profiler::class,
    new Profiler($storage, DriverFactory::detect(), 'My app'),
);

$app->middleware()->incoming(ProfilerMiddleware::class);
```

Profiled requests should not run concurrently on the shared instance (see [Runtime](runtime.md)): sample with a `when:` condition or enable the middleware per environment, not on every production request.

## Boot failures

An exception thrown while booting (a failing `config.php`, an invalid route table) happens before any request cycle exists: there is no `HttpContext`, so `onError()` hooks never run and nothing is reported to Sentry. `App::run()` still turns it into a `500` through the debug page in debug mode, `Server error` otherwise — but nothing is logged either. This is a deliberate boundary: if boot failures need alerting, watch the SAPI / supervisor logs and keep `boot()` trivial.

## Tests

Automated tests keep using their own fakes (in-memory mailers, array sessions, `NullLogger`): Buggregator is a development companion, not a test harness.

