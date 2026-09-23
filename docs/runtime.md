# Runtime independence

Kaly is an HTTP framework, not an execution runtime.

The same booted application may be hosted by:

- a traditional request-per-process SAPI (PHP-FPM, PHP built-in server);
- a sequential long-lived worker (FrankenPHP, RoadRunner);
- a runtime executing several requests concurrently (Fibers, event loop).

Kaly therefore follows these rules:

1. Kernel and application services must not store request state.
2. Request state belongs to [HttpContext](http-context.md) or objects created for that request.
3. The application container is application-scoped: what it shares must be safe to share.
4. Kaly does not own an event loop, Fiber scheduler or async API.
5. Blocking vs suspendable I/O is a property of application/runtime adapters, not of the Kaly kernel.
6. Native PHP process-global facilities may have stricter runtime limitations — see below.

## Consequences

Middlewares must be stateless: anything a request establishes goes in the
context, never in a middleware property. `WorkerTest` locks the sequential
contract (one cycle finishes, the next starts clean); `ConcurrentRequestTest`
locks the concurrent one (two interleaved cycles on one booted app, each keeps
its route, locale, cookies, session, middleware trace and response).

## Cookies

Cookies are the model every request-scoped state should follow:

> Kaly never emits cookies through PHP globals. Cookies are read from the
> PSR-7 request and emitted as `Set-Cookie` headers on the PSR-7 response.

`Cookies` holds no shared state, so concurrent cycles are isolated by
construction. The application baseline (path, domain, secure, httponly,
samesite...) lives in the application-scoped, immutable `CookiePolicy`,
shared by `Cookies` and the session cookie; cookie *values* stay
request-scoped. `SetCookieHeader` is the stateless primitive underneath:
name + value + params in, header string out.

## Native PHP sessions

`NativePhpSession` wraps the process-global `$_SESSION`. It is safe for
sequential execution — Kaly resets the native session id on `start()` and
`close()` so nothing leaks between requests — but two requests running
concurrently in the same process must not share it.

```text
NativePhpSession
    sequential worker    ✓
    concurrent Fibers    ✗

request-scoped storage (eg: ArraySession)
    sequential worker    ✓
    concurrent Fibers    ✓
```

Concurrent runtimes must inject a request-scoped `SessionInterface`
implementation (for example with `HttpContext::useSession()`). Cookie- or
server-backed session policies are a decision of the application or of a
dedicated package, not of the Kaly core.
