# Debugging

Kaly has no Buggregator integration code, and needs none: everything an application produces in development (dumps, logs, emails, errors, outgoing HTTP) goes through standard protocols that Buggregator aggregates. Point the DSNs below at it and it becomes the local viewer for all of them.

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
```

## Dumps

`d()` dumps through Symfony VarDumper's `dump()` (`var_dump()` fallback) and never stops, so it stays safe inside a worker or a Fiber. `dd()` dumps and exits. Both do nothing in production (`APP_DEBUG` disabled).

With the recipe above, `dump()` output — including the source location — is sent to Buggregator over TCP instead of polluting the HTTP response.

## Logs

Bind a real PSR-3 logger (eg: Monolog with a socket handler to `LOG_SOCKET_URL`) and the Kaly diagnostics follow it automatically, including the pipeline trace. See [logging](logging.md).

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

## Tests

Automated tests keep using their own fakes (in-memory mailers, array sessions, `NullLogger`): Buggregator is a development companion, not a test harness.
