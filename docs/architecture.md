# Architecture

Kaly composes an application from modules and runs each request through fixed
bands. Two sentences carry the whole design:

```text
one request -> one context -> the whole cycle -> one response
```

## Composition

`App` owns the boot: modules declare services and routing in `config.php`,
definitions merge by priority, the kernel is built once. There is no global
route file and no service locator at runtime: controllers receive services by
constructor, actions receive url segments (plus one trailing input) by
signature. See [Modules](modules.md) and [DI](di.md).

## Bands

```text
incoming -> routing -> routed -> [route middlewares] -> dispatcher
  -> commit (session, cookies) -> outgoing -> terminate hooks
```

- **incoming**: before routing, no route yet (`HttpContext::from()` works,
  `route()`/`locale()` throw).
- **routing**: structural, not configurable — one module owns the url.
- **routed + dispatcher**: `route()` and `locale()` guaranteed; `$ctx->url()`
  generates for the request locale.
- **commit**: session and cookies owned by the context are written back,
  whatever produced the response.
- **outgoing**: transforms the final response (`Response -> Response`);
  `always` middlewares also cover error responses.
- **terminate**: observability only, never the response.

## Ownership

What belongs to a cycle lives in `HttpContext` (route, locale, session,
cookies, response); what is shared lives in application-scoped services
(router tables compiled once, `CookiePolicy`, translator). Nothing request
scoped is static — except PHP's own `$_SESSION`, which is why concurrent
runtimes bind a `SessionFactoryInterface` returning request-scoped storage.
See [Http context](http-context.md) and [Runtime](runtime.md).

## Runtime vs tests vs development

```text
Production    HTTP runtime ────────► App
Tests         TestClient ──────────► App
Development   PHP built-in server ─► App
```

Kaly owns the composition and the in-memory solicitation (`Kaly\Test`),
neither the development server nor a CLI. See [Testing](testing.md) and
[Serving](serving.md).
